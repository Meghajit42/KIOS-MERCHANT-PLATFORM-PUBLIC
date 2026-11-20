(() => {
  const pollIntervalMs = 1500;
  const requestTimeoutMs = 5000;
  let inFlight = false;
  let lastSnapshot = '';
  let rotation = 0;
  const pageSizes = { 'preparing-tokens': () => 8, 'ready-tokens': () => window.innerWidth > 900 ? 8 : 12, 'collected-tokens': () => 8 };
  const rotateTokens = () => {
    Object.entries(pageSizes).forEach(([id, capacity]) => {
      const size = capacity();
      const list = document.getElementById(id);
      if (!list) return;
      const items = Array.from(list.children);
      const pages = Math.max(1, Math.ceil(items.length / size));
      const page = rotation % pages;
      items.forEach((item, index) => { item.hidden = index < page * size || index >= (page + 1) * size; });
      list.setAttribute('aria-label', pages > 1 ? `Page ${page + 1} of ${pages}` : '');
    });
  };
  const scheduleRotation = () => {
    const pages = ['preparing-tokens', 'ready-tokens'].map((id) => {
      const list = document.getElementById(id);
      return Math.ceil((list?.children.length || 0) / pageSizes[id]());
    });
    const delay = Math.max(3000, Math.min(8000, 32000 / Math.max(1, ...pages)));
    window.setTimeout(() => { rotation += 1; rotateTokens(); scheduleRotation(); }, delay);
  };
  scheduleRotation();
  window.addEventListener('resize', rotateTokens);

  const refreshDisplay = async () => {
    if (inFlight) return;
    inFlight = true;
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), requestTimeoutMs);

    try {
      const response = await fetch('/display-data.php', {
        method: 'GET',
        credentials: 'omit',
        cache: 'no-store',
        signal: controller.signal,
        headers: { Accept: 'application/json' },
      });
      if (!response.ok) throw new Error('Display request failed.');

      const data = await response.json();
      if (typeof data !== 'object' || data === null || typeof data.outlet !== 'string' || !Array.isArray(data.tokens)) {
        throw new Error('Display response is invalid.');
      }
      const validTokens = data.tokens.every((token) =>
        typeof token === 'object'
        && token !== null
        && typeof token.token === 'string'
        && token.token.length > 0
        && (token.publicStatus === 'PREPARING' || token.publicStatus === 'READY' || token.publicStatus === 'COLLECTED')
        && typeof token.updatedAt === 'string'
        && !Number.isNaN(Date.parse(token.updatedAt))
      );
      if (!validTokens) throw new Error('Display token data is invalid.');
      if ('shopOpen' in data && typeof data.shopOpen !== 'boolean') throw new Error('Shop status is invalid.');

      const preparingList = document.getElementById('preparing-tokens');
      const readyList = document.getElementById('ready-tokens');
      const collectedList = document.getElementById('collected-tokens');
      const outletDisplay = document.getElementById('display-outlet');
      const connection = document.getElementById('display-connection');
      const warning = document.getElementById('display-warning');
      const updated = document.getElementById('display-updated');
      const empty = document.getElementById('display-empty');
      const board = document.getElementById('display-board');
      const shopStatus = document.getElementById('shop-status');
      const collectedSection = document.getElementById('collected-section');
      if (!preparingList || !readyList || !outletDisplay || !connection || !warning || !updated || !empty) {
        throw new Error('Display elements are unavailable.');
      }

      const snapshot = JSON.stringify([data.outlet, data.tokens, data.shopOpen]);
      if (snapshot !== lastSnapshot) {
        const preparingFragment = document.createDocumentFragment();
        const readyFragment = document.createDocumentFragment();
        const collectedFragment = document.createDocumentFragment();
        data.tokens.forEach((token) => {
          const item = document.createElement('li');
          item.className = 'display-token';
          item.textContent = token.token;
          (token.publicStatus === 'PREPARING' ? preparingFragment : (token.publicStatus === 'READY' ? readyFragment : collectedFragment)).appendChild(item);
        });

        preparingList.replaceChildren(preparingFragment);
        readyList.replaceChildren(readyFragment);
        collectedList?.replaceChildren(collectedFragment);
        outletDisplay.textContent = data.outlet;
        const active = data.tokens.some((token) => token.publicStatus === 'PREPARING' || token.publicStatus === 'READY');
        empty.hidden = active;
        if (board) board.hidden = !active;
        if (collectedSection) collectedSection.hidden = !data.tokens.some((token) => token.publicStatus === 'COLLECTED');
        if (shopStatus) shopStatus.textContent = typeof data.shopOpen !== 'boolean' ? 'Shop status unavailable' : (data.shopOpen ? 'SHOP OPEN' : 'SHOP CLOSED · Ordering is currently unavailable');
        rotateTokens();
        lastSnapshot = snapshot;
      }
      updated.textContent = new Date().toLocaleTimeString();
      connection.textContent = 'Connected · live updates active';
      warning.hidden = true;
    } catch {
      const preparingList = document.getElementById('preparing-tokens');
      const readyList = document.getElementById('ready-tokens');
      const collectedList = document.getElementById('collected-tokens');
      const connection = document.getElementById('display-connection');
      const empty = document.getElementById('display-empty');
      const board = document.getElementById('display-board');
      const shopStatus = document.getElementById('shop-status');
      const collectedSection = document.getElementById('collected-section');
      preparingList?.replaceChildren();
      readyList?.replaceChildren();
      collectedList?.replaceChildren();
      if (empty) empty.hidden = true;
      if (board) board.hidden = true;
      if (collectedSection) collectedSection.hidden = true;
      if (shopStatus) shopStatus.textContent = 'Shop status unavailable';
      if (connection) connection.textContent = 'Offline · reconnecting';
      lastSnapshot = '';
      const warning = document.getElementById('display-warning');
      if (warning) warning.hidden = false;
    } finally {
      window.clearTimeout(timeout);
      inFlight = false;
      window.setTimeout(refreshDisplay, pollIntervalMs);
    }
  };

  refreshDisplay();
})();
