// Guarda la app para que abra rápido; los datos siempre van al servidor. También recibe los avisos.
const C='crazyhq-v12';
const SHELL=['./','index.html','mark.png','word.png','icon-192.png','manifest.webmanifest'];
self.addEventListener('install',e=>{e.waitUntil(caches.open(C).then(c=>c.addAll(SHELL)));self.skipWaiting()});
self.addEventListener('activate',e=>{e.waitUntil(caches.keys().then(ks=>Promise.all(ks.filter(k=>k!==C).map(k=>caches.delete(k)))));self.clients.claim()});
self.addEventListener('fetch',e=>{const u=new URL(e.request.url);
  if(e.request.method!=='GET'||u.pathname.includes('/api/'))return;
  e.respondWith(fetch(e.request).then(r=>{const cp=r.clone();caches.open(C).then(c=>c.put(e.request,cp));return r}).catch(()=>caches.match(e.request)))});
// Aviso que llega aunque la app esté cerrada
self.addEventListener('push',e=>{let d={};try{d=e.data?e.data.json():{}}catch{d={body:e.data&&e.data.text()}}
  e.waitUntil(self.registration.showNotification(d.title||'Crazy HQ',{body:d.body||'',icon:'icon-192.png',badge:'icon-192.png',tag:d.tag||undefined,data:{url:d.url||'./'}}))});
// Al tocarlo, abre la app en la pantalla que toca
self.addEventListener('notificationclick',e=>{e.notification.close();const url=new URL((e.notification.data&&e.notification.data.url)||'./',self.registration.scope).href;
  e.waitUntil(clients.matchAll({type:'window',includeUncontrolled:true}).then(cs=>{const c=cs.find(x=>x.url.startsWith(self.registration.scope));if(c){c.focus();return c.navigate?c.navigate(url):null}return clients.openWindow(url)}))});
