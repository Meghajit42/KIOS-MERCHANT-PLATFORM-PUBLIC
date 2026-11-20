'use strict';
const fs=require('fs'),assert=require('assert');
const root='C:/KiosB0',spec=JSON.parse(fs.readFileSync(root+'/docs/M07A_OPENAPI.json','utf8'));
assert.equal(spec.openapi,'3.1.0');
const routes=['/api/v1/merchant','/api/v1/outlet','/api/v1/catalogue','/api/v1/availability','/api/v1/configuration','/api/v1/operational-status','/api/v1/restaurants','/api/v1/customer-catalogue'];
for(const path of routes){const item=spec.paths[path];assert(item,path);for(const method of ['get','head']){const op=item[method];assert(op,method+path);assert(op.parameters.some(p=>p.name==='merchant'&&p.required));assert(op.parameters.some(p=>p.name==='outlet'&&p.required));assert(op.security.some(s=>s.privateGate&&s.staffSession));for(const code of ['200','400','401','403','404','405','429','503'])assert(op.responses[code],path+' '+code);if(method==='get')assert(op.responses['200'].content['application/json'].schema.$ref);}}
for(const [name,schema]of Object.entries(spec.components.schemas)){assert(schema&&typeof schema==='object',name);for(const ref of JSON.stringify(schema).matchAll(/#\/components\/schemas\/([A-Za-z0-9_]+)/g))assert(spec.components.schemas[ref[1]],name+' unresolved '+ref[1]);}
const p=spec.components.schemas.CustomerProduct.properties;for(const key of ['productId','merchantId','outletId','name','category','categoryName','description','servingOptions','displayPricePaise','imageReference','vegetarian','available','acceptingOrders'])assert(p[key],key);
assert.equal(p.available.const,true);assert.equal(p.acceptingOrders.const,false);
const api=fs.readFileSync(root+'/app/MerchantOperations/PrivateApi.php','utf8'),discovery=fs.readFileSync(root+'/app/MerchantOperations/PrivateDiscovery.php','utf8'),guide=fs.readFileSync(root+'/docs/M07B_DEVELOPER_INTEGRATION.md','utf8');
for(const route of ['restaurants','customer-catalogue'])assert(api.includes(route)&&spec.paths['/api/v1/'+route]);
for(const field of ['merchant','outlet','page','per_page','q','category','cuisine','sort'])assert(guide.includes('`'+field+'`'));
assert(discovery.includes("KIOS_PRIVATE_DISCOVERY_SCOPES")&&api.includes("KIOS_PRIVATE_DISCOVERY_ENABLED"));
const code=[...guide.matchAll(/```json\s*([\s\S]*?)```/g)].map(x=>x[1]);assert(code.length>=2);for(const x of code)JSON.parse(x.trim());
console.log(JSON.stringify({status:'PASS',openapi:'3.1.0',routes:routes.length,operations:routes.length*2,schemas:Object.keys(spec.components.schemas).length,guideJsonExamples:code.length,checks:routes.length*2*11+19}));
