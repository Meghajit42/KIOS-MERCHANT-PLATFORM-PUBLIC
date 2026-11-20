$ErrorActionPreference = 'Stop'

function Write-Result([string]$Category, [bool]$Success) {
    [Console]::Out.WriteLine((ConvertTo-Json -Compress @{ success = $Success; category = $Category }))
}

try {
    $encoded = [Console]::In.ReadLine()
    if ([string]::IsNullOrWhiteSpace($encoded) -or $encoded.Length -gt 2000000) {
        Write-Result 'spooler_error' $false
        exit 2
    }
    $requestJson = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($encoded))
    $request = ConvertFrom-Json -InputObject $requestJson
    $printerName = [string]$request.printerName
    $payload = [Convert]::FromBase64String([string]$request.bytesBase64)
    if ([string]::IsNullOrWhiteSpace($printerName) -or $printerName.Length -gt 160 -or $payload.Length -lt 1) {
        Write-Result 'spooler_error' $false
        exit 2
    }
    try {
        $printer = Get-Printer -Name $printerName -ErrorAction Stop
    } catch {
        Write-Result 'printer_not_found' $false
        exit 3
    }
    if ([string]$printer.PrinterStatus -match 'Offline|Paused|Error') {
        Write-Result 'printer_offline' $false
        exit 4
    }

    $native = @'
using System;
using System.Runtime.InteropServices;
public static class KiosRawPrinter {
    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    public struct DOC_INFO_1 {
        [MarshalAs(UnmanagedType.LPWStr)] public string pDocName;
        [MarshalAs(UnmanagedType.LPWStr)] public string pOutputFile;
        [MarshalAs(UnmanagedType.LPWStr)] public string pDatatype;
    }
    [DllImport("winspool.drv", SetLastError = true, CharSet = CharSet.Unicode)]
    public static extern bool OpenPrinter(string name, out IntPtr handle, IntPtr defaults);
    [DllImport("winspool.drv", SetLastError = true)]
    public static extern bool ClosePrinter(IntPtr handle);
    [DllImport("winspool.drv", SetLastError = true, CharSet = CharSet.Unicode)]
    public static extern int StartDocPrinter(IntPtr handle, int level, ref DOC_INFO_1 info);
    [DllImport("winspool.drv", SetLastError = true)]
    public static extern bool EndDocPrinter(IntPtr handle);
    [DllImport("winspool.drv", SetLastError = true)]
    public static extern bool StartPagePrinter(IntPtr handle);
    [DllImport("winspool.drv", SetLastError = true)]
    public static extern bool EndPagePrinter(IntPtr handle);
    [DllImport("winspool.drv", SetLastError = true)]
    public static extern bool WritePrinter(IntPtr handle, IntPtr data, int count, out int written);
}
'@
    Add-Type -TypeDefinition $native
    $handle = [IntPtr]::Zero
    $documentStarted = $false
    try {
        if (-not [KiosRawPrinter]::OpenPrinter($printerName, [ref]$handle, [IntPtr]::Zero)) {
            Write-Result 'spooler_error' $false
            exit 5
        }
        $info = New-Object KiosRawPrinter+DOC_INFO_1
        $info.pDocName = 'Kios synthetic print job'
        $info.pOutputFile = $null
        $info.pDatatype = 'RAW'
        if ([KiosRawPrinter]::StartDocPrinter($handle, 1, [ref]$info) -eq 0) {
            Write-Result 'spooler_error' $false
            exit 6
        }
        $documentStarted = $true
        if (-not [KiosRawPrinter]::StartPagePrinter($handle)) {
            Write-Result 'spooler_error' $false
            exit 7
        }
        $buffer = [Runtime.InteropServices.Marshal]::AllocHGlobal($payload.Length)
        try {
            [Runtime.InteropServices.Marshal]::Copy($payload, 0, $buffer, $payload.Length)
            $written = 0
            if (-not [KiosRawPrinter]::WritePrinter($handle, $buffer, $payload.Length, [ref]$written) -or $written -ne $payload.Length) {
                Write-Result 'spooler_error' $false
                exit 8
            }
        } finally {
            [Runtime.InteropServices.Marshal]::FreeHGlobal($buffer)
        }
        if (-not [KiosRawPrinter]::EndPagePrinter($handle)) {
            Write-Result 'spooler_error' $false
            exit 9
        }
        if (-not [KiosRawPrinter]::EndDocPrinter($handle)) {
            Write-Result 'spooler_error' $false
            exit 10
        }
        $documentStarted = $false
        Write-Result 'printed' $true
    } finally {
        if ($documentStarted -and $handle -ne [IntPtr]::Zero) {
            [void][KiosRawPrinter]::EndDocPrinter($handle)
        }
        if ($handle -ne [IntPtr]::Zero) {
            [void][KiosRawPrinter]::ClosePrinter($handle)
        }
    }
} catch {
    Write-Result 'spooler_error' $false
    exit 11
}
