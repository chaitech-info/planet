// Kainat: WebGL (Three.js) 3D kosmos. Hər proyekt bir planet, hər istifadəçi bir ay.
import * as THREE from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';

const REDUCE = matchMedia('(prefers-reduced-motion: reduce)').matches;
const STC = { working: '#fbbf24', waiting: '#f87171', idle: '#4ade80', offline: '#6f6f7c', ended: '#6f6f7c' };
const uT = { value: 0 };
const uSun = { value: new THREE.Vector3(0, 0, 0) };

const hash = s => { let h = 2166136261; for (const c of s) { h ^= c.charCodeAt(0); h = Math.imul(h, 16777619); } return h >>> 0; };
const rnd = seed => { let a = seed >>> 0; return () => { a = (a + 0x6D2B79F5) | 0; let t = Math.imul(a ^ (a >>> 15), 1 | a); t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t; return ((t ^ (t >>> 14)) >>> 0) / 4294967296; }; };
const slotR = i => 22 + i * 14;
const ease = u => u < .5 ? 4 * u * u * u : 1 - Math.pow(-2 * u + 2, 3) / 2;

/* ---------- GLSL ---------- */
const NOISE = `
vec3 hash3(vec3 p){ p=vec3(dot(p,vec3(127.1,311.7,74.7)),dot(p,vec3(269.5,183.3,246.1)),dot(p,vec3(113.5,271.9,124.6))); return -1.+2.*fract(sin(p)*43758.5453123); }
float gnoise(vec3 p){ vec3 i=floor(p), f=fract(p); vec3 u=f*f*(3.-2.*f);
  return mix(mix(mix(dot(hash3(i),f), dot(hash3(i+vec3(1,0,0)),f-vec3(1,0,0)),u.x),
                 mix(dot(hash3(i+vec3(0,1,0)),f-vec3(0,1,0)), dot(hash3(i+vec3(1,1,0)),f-vec3(1,1,0)),u.x),u.y),
             mix(mix(dot(hash3(i+vec3(0,0,1)),f-vec3(0,0,1)), dot(hash3(i+vec3(1,0,1)),f-vec3(1,0,1)),u.x),
                 mix(dot(hash3(i+vec3(0,1,1)),f-vec3(0,1,1)), dot(hash3(i+vec3(1,1,1)),f-vec3(1,1,1)),u.x),u.y),u.z); }
float fbm(vec3 p){ float a=.5,s=0.; for(int i=0;i<5;i++){ s+=a*gnoise(p); p=p*2.03+vec3(1.7,9.2,3.1); a*=.5; } return s; }
`;

const PLANET_VS = `
varying vec3 vObj; varying vec3 vNW; varying vec3 vWP;
void main(){ vObj=position; vNW=normalize(mat3(modelMatrix)*normal); vec4 w=modelMatrix*vec4(position,1.); vWP=w.xyz; gl_Position=projectionMatrix*viewMatrix*w; }`;

const PLANET_FS = `
uniform float uTime, uSeed, uType, uDim, uLights, uHot, uHurt; uniform vec3 uTint, uAtm, uLight;
uniform vec4 uDmgA[6]; uniform vec4 uDmgB[6];
varying vec3 vObj; varying vec3 vNW; varying vec3 vWP;
${NOISE}
void main(){
  vec3 n=normalize(vObj);
  vec3 p=n*1.8+vec3(uSeed*37.,uSeed*11.,uSeed*23.);
  vec3 col=vec3(.5); float spec=0., land=0., emis=0.;
  if(uType<.5){
    float h=fbm(p*1.15); float lnd=smoothstep(.0,.05,h);
    float ice=smoothstep(.74,.9,abs(n.y)+h*.35);
    vec3 ocean=mix(vec3(.012,.06,.2),vec3(.04,.3,.5),smoothstep(-.32,.0,h));
    vec3 grass=mix(vec3(.09,.3,.07),vec3(.4,.35,.16),smoothstep(.02,.3,h));
    grass=mix(grass,vec3(.52,.48,.42),smoothstep(.26,.44,h));
    col=mix(ocean,grass,lnd); col=mix(col,vec3(.94,.97,1.),ice);
    spec=(1.-lnd)*(1.-ice); land=lnd*(1.-ice);
  } else if(uType<1.5){
    float w=fbm(vec3(p.x*.5,p.y*2.6,p.z*.5)+vec3(uTime*.02,0.,0.));
    float y=n.y*7.+w*2.4;
    float b=.5+.5*sin(y*2.4)+.25*sin(y*6.1+1.3);
    vec3 c1=uTint, c2=mix(uTint,vec3(1.,.93,.8),.6), c3=mix(uTint,vec3(.1,.08,.14),.7);
    col=mix(c1,c2,smoothstep(.2,.95,b));
    col=mix(col,c3,smoothstep(.05,.7,fbm(p*vec3(1.,4.5,1.)+3.)+.12));
    float d=distance(n,normalize(vec3(.6,-.25,.75))); col=mix(col,vec3(.85,.36,.2),smoothstep(.3,.08,d)*.75);
  } else if(uType<2.5){
    float h=fbm(p*1.4); float c=abs(fbm(p*2.6+vec3(7.)));
    float cr=smoothstep(.075,.0,c);
    col=mix(vec3(.04,.035,.04),vec3(.22,.14,.1),smoothstep(-.2,.3,h));
    vec3 hot=mix(vec3(1.,.22,.02),vec3(1.,.8,.25),cr);
    col=mix(col,hot,cr); emis=cr*(.75+.25*sin(uTime*1.4+h*22.));
  } else if(uType<3.5){
    float h=fbm(p*2.); float c=fbm(p*5.);
    col=mix(vec3(.26),vec3(.64),smoothstep(-.3,.3,h)); col*=.82+.36*c; col=mix(col,uTint,.3);
  } else if(uType<4.5){
    float h=fbm(p*1.6); float cr=smoothstep(.06,.0,abs(fbm(p*3.+5.)));
    col=mix(vec3(.72,.86,.98),vec3(.96,.99,1.),smoothstep(-.2,.3,h));
    col=mix(col,vec3(.3,.58,.85),cr*.85); spec=.6;
  } else {
    float h=fbm(p*1.3); float d=fbm(vec3(p.x*3.,p.y*8.,p.z*3.)+h*2.);
    col=mix(vec3(.6,.36,.17),vec3(.88,.66,.4),smoothstep(-.3,.4,h));
    col=mix(col,vec3(.38,.19,.09),smoothstep(.2,.5,d)*.55);
    col*=1.-smoothstep(.1,.0,abs(fbm(p*5.+2.)))*.18;
  }
  float g=dot(col,vec3(.299,.587,.114)); col=mix(vec3(g),col,uDim);
  // raket zədəsi: krater, kömürləşmiş haşiyə, çatlar, ilk saniyələrdə közərmə
  for(int i=0;i<6;i++){
    float sc=uDmgA[i].w;
    if(sc>.003){
      float rad=uDmgB[i].z, hot=uDmgB[i].x, sd=uDmgB[i].y;
      float dd=length(n-uDmgA[i].xyz)/rad+gnoise(n*11.+sd)*.2;
      float cr=smoothstep(1.,.5,dd);
      float rim=smoothstep(.55,1.,dd)*smoothstep(1.45,1.,dd);
      float halo=smoothstep(2.3,.9,dd)*(.55+.45*gnoise(n*26.+sd*3.));
      float ck=smoothstep(.07,.0,abs(gnoise(n*15.+sd*7.)))*smoothstep(2.6,.8,dd);
      col=mix(col,col*.2+vec3(.035,.025,.02),halo*.7*sc);
      col=mix(col,vec3(.02,.014,.012),max(cr,ck*.85)*sc);
      col+=rim*vec3(.22,.12,.07)*sc;
      emis+=(cr*(.7+.3*gnoise(n*30.+uTime*3.+sd))+ck*.9)*hot;
      land*=1.-cr*sc;
    }
  }
  vec3 N=normalize(vNW); vec3 L=normalize(uLight-vWP); vec3 V=normalize(cameraPosition-vWP);
  float ndl=dot(N,L); float day=smoothstep(-.06,.42,ndl);
  float sp=pow(max(dot(N,normalize(L+V)),0.),70.)*spec*day;
  float fres=pow(1.-max(dot(N,V),0.),3.);
  vec3 c=col*(day*1.25+.15+.11*max(dot(N,V),0.))*mix(vec3(.62,.7,1.),vec3(1.,.97,.92),day);
  c+=sp*vec3(1.,.95,.85)*.75;
  float nightF=1.-smoothstep(-.1,.2,ndl);
  c+=land*nightF*smoothstep(.55,.8,fbm(p*9.))*uLights*vec3(1.,.78,.4)*.95;
  c+=emis*vec3(1.,.5,.15)*1.5;
  c+=fres*uAtm*(day*.85+.06)*.65;
  c*=1.+uHot*.22;
  c=mix(c,c*vec3(1.5,.55,.4),uHurt*.5)+uHurt*vec3(.2,.035,0.)*(.6+.4*sin(uTime*14.));
  gl_FragColor=vec4(c,1.);
}`;

const CLOUD_FS = `
uniform float uTime, uSeed; uniform vec3 uLight;
varying vec3 vObj; varying vec3 vNW; varying vec3 vWP;
${NOISE}
void main(){
  vec3 n=normalize(vObj);
  float a=smoothstep(.02,.4,fbm(n*3.4+vec3(uSeed*19.)+vec3(uTime*.02,0.,0.)));
  vec3 N=normalize(vNW); vec3 L=normalize(uLight-vWP);
  float day=smoothstep(-.1,.5,dot(N,L));
  gl_FragColor=vec4(vec3(1.)*(day*1.15+.03),a*.8);
}`;

const ATM_VS = `
varying vec3 vN; varying vec3 vNW; varying vec3 vWP;
void main(){ vN=normalize(normalMatrix*normal); vNW=normalize(mat3(modelMatrix)*normal); vec4 w=modelMatrix*vec4(position,1.); vWP=w.xyz; gl_Position=projectionMatrix*viewMatrix*w; }`;
const ATM_FS = `
uniform vec3 uCol; uniform vec3 uLight; uniform float uRatio, uPow, uAmt;
varying vec3 vN; varying vec3 vNW; varying vec3 vWP;
void main(){
  float edge=sqrt(1.-1./(uRatio*uRatio));
  float i=pow(clamp(-vN.z/edge,0.,1.),2.2);
  float day=.28+.72*smoothstep(-.45,.55,dot(normalize(vNW),normalize(uLight-vWP)));
  gl_FragColor=vec4(uCol*i*day*uAmt,i*day*uAmt);
}`;

const RING_VS = `varying vec3 vP; varying vec3 vWP; void main(){ vP=position; vec4 w=modelMatrix*vec4(position,1.); vWP=w.xyz; gl_Position=projectionMatrix*viewMatrix*w; }`;
const RING_FS = `
uniform float uIn, uOut, uSeed, uR; uniform vec3 uTint, uLight, uPC;
varying vec3 vP; varying vec3 vWP;
void main(){
  float t=(length(vP.xy)-uIn)/(uOut-uIn);
  float band=.5+.5*sin(t*38.+uSeed*20.)*(.6+.4*sin(t*11.+uSeed*5.));
  float edge=smoothstep(.0,.05,t)*smoothstep(1.,.93,t);
  float gap=1.-.8*smoothstep(.0,.03,t-.56)*smoothstep(.03,.0,t-.62);
  float a=edge*gap*(.22+.6*band);
  vec3 c=mix(uTint,vec3(.93,.89,.82),band*.6)*(.5+.55*band);
  vec3 L=normalize(uLight-vWP); vec3 d=uPC-vWP; float along=dot(d,L); vec3 perp=d-along*L;
  float sh=along>0.?smoothstep(uR*.97,uR*1.12,length(perp)):1.;
  c*=.22+.9*sh; a*=.4+.6*sh;
  gl_FragColor=vec4(c,a*.92);
}`;

const SUN_VS = `varying vec3 vObj; varying vec3 vNv; void main(){ vObj=position; vNv=normalize(normalMatrix*normal); gl_Position=projectionMatrix*modelViewMatrix*vec4(position,1.); }`;
const SUN_FS = `
uniform float uTime; varying vec3 vObj; varying vec3 vNv;
${NOISE}
void main(){
  vec3 n=normalize(vObj);
  float g=fbm(n*3.+vec3(uTime*.07)); float g2=fbm(n*9.-vec3(uTime*.16));
  vec3 c=mix(vec3(1.,.36,.04),vec3(1.,.82,.32),smoothstep(-.2,.45,g+.35*g2));
  float mu=max(vNv.z,0.);
  c*=.5+.75*pow(mu,.6); c+=vec3(1.,.9,.6)*pow(mu,5.)*.35;
  gl_FragColor=vec4(c,1.);
}`;

const STAR_VS = `
attribute float size; attribute float phase; attribute vec3 color; uniform float uTime, uPR; varying vec3 vC; varying float vTw;
void main(){ vC=color; vTw=.62+.38*sin(uTime*(.5+phase*.9)+phase*60.); gl_Position=projectionMatrix*modelViewMatrix*vec4(position,1.); gl_PointSize=size*uPR; }`;
const STAR_FS = `
varying vec3 vC; varying float vTw;
void main(){ float d=length(gl_PointCoord-.5); float a=pow(smoothstep(.5,.0,d),1.7); gl_FragColor=vec4(vC*vTw,a*vTw); }`;

const BURST_VS = `
attribute vec3 vel; attribute float life; attribute float size;
uniform float uAge, uPx, uG; varying float vA; varying float vH;
void main(){
  float k=uAge/life;
  vec3 p=position+vel*uAge-normalize(position)*uG*uAge*uAge;
  vA=clamp(1.-k,0.,1.); vH=clamp(1.-k*1.5,0.,1.);
  vec4 mv=modelViewMatrix*vec4(p,1.);
  gl_Position=projectionMatrix*mv;
  gl_PointSize=k>1.?0.:max(2.4,size*uPx/-mv.z*(1.-.45*k));
}`;
const BURST_FS = `
uniform vec3 uCol; varying float vA; varying float vH;
void main(){
  float d=length(gl_PointCoord-.5); float a=smoothstep(.5,0.,d); a*=a;
  vec3 c=mix(uCol,vec3(1.,.96,.78),vH);
  gl_FragColor=vec4(c*(1.+vH*1.2),a*vA);
}`;

const TRAIL_VS = `
attribute float aBorn; attribute float aSz; uniform float uNow, uLife, uPx;
varying float vK;
void main(){
  float k=(uNow-aBorn)/uLife; vK=k;
  vec4 mv=modelViewMatrix*vec4(position,1.);
  gl_Position=projectionMatrix*mv;
  gl_PointSize=(k<0.||k>1.)?0.:max(2.,aSz*uPx/-mv.z*(1.-.55*k));
}`;
const TRAIL_FS = `
uniform float uCool; varying float vK;
void main(){
  float d=length(gl_PointCoord-.5); float a=smoothstep(.5,0.,d); a*=a;
  vec3 c=mix(vec3(1.,.92,.7),mix(vec3(1.,.5,.12),vec3(.5,.16,.08),clamp(vK*1.6-.4,0.,1.)),clamp(vK*2.4,0.,1.));
  c=mix(c,c.zyx*vec3(.75,1.,1.1),uCool);
  gl_FragColor=vec4(c,a*pow(clamp(1.-vK,0.,1.),1.4)*.9);
}`;

const FIG_VS = `varying vec3 vN; varying vec3 vWP; void main(){ vN=normalize(mat3(modelMatrix)*normal); vec4 w=modelMatrix*vec4(position,1.); vWP=w.xyz; gl_Position=projectionMatrix*viewMatrix*w; }`;
const FIG_FS = `uniform vec3 uColor, uLight; uniform float uEm; varying vec3 vN; varying vec3 vWP;
void main(){ float d=max(dot(normalize(vN),normalize(uLight-vWP)),0.); vec3 c=uColor*(.4+.85*d)+uColor*uEm; gl_FragColor=vec4(c,1.); }`;

const SKY_VS = `varying vec3 vDir; void main(){ vDir=position; gl_Position=projectionMatrix*modelViewMatrix*vec4(position,1.); }`;
const SKY_FS = `
uniform float uTime; varying vec3 vDir;
${NOISE}
void main(){
  vec3 d=normalize(vDir);
  float a=fbm(d*2.1+vec3(0.,0.,uTime*.004));
  float b=fbm(d*3.7+vec3(11.,3.,uTime*.006));
  vec3 c=vec3(.006,.008,.02);
  c+=vec3(.2,.1,.38)*smoothstep(-.05,.5,a)*.55;
  c+=vec3(.05,.22,.36)*smoothstep(0.,.55,b)*.34*smoothstep(-.2,.6,a);
  c+=vec3(.38,.1,.2)*smoothstep(.15,.6,fbm(d*1.6+7.))*.26;
  gl_FragColor=vec4(c,1.);
}`;

/* ---------- köməkçi teksturlar ---------- */
function radialTex(stops) {
  const c = document.createElement('canvas'); c.width = c.height = 256;
  const g = c.getContext('2d'), gr = g.createRadialGradient(128, 128, 0, 128, 128, 128);
  for (const [o, col] of stops) gr.addColorStop(o, col);
  g.fillStyle = gr; g.fillRect(0, 0, 256, 256);
  const t = new THREE.CanvasTexture(c); t.colorSpace = THREE.SRGBColorSpace; return t;
}
function ringTex() {
  const c = document.createElement('canvas'); c.width = c.height = 256;
  const g = c.getContext('2d'); g.clearRect(0, 0, 256, 256);
  g.strokeStyle = '#fff'; g.lineWidth = 5; g.beginPath(); g.arc(128, 128, 118, 0, 6.2832); g.stroke();
  const t = new THREE.CanvasTexture(c); t.colorSpace = THREE.SRGBColorSpace; return t;
}

/* ---------- Kainat ---------- */
let renderer, scene, camera, controls, box, canvas, lblBox, sunLbl;
let W = 1, H = 1, active = false, last = 0, speed = 1, tScene = 0;
let stars, sky, sunMesh, sunGlow1, sunGlow2;
let glowTex, beaconTex;
const planets = new Map();
const rand = (a, b) => a + Math.random() * (b - a);
const PERF = { Askar: 'dance', Emil: 'guitar' };
let focusName = null, pendingFocus = null, fly = null, hovered = null, userMoved = false, maxR = 40;
const ndc = new THREE.Vector2(9, 9), ray = new THREE.Raycaster(), tmp = new THREE.Vector3(), tmp2 = new THREE.Vector3();
let downAt = null, moved = false, inset = 0, G = 0;
let pr = 1, prMax = 1, ema = .016, prT = 0, zT = null, quality = 'auto', baseDt = .0167, frames = 0;

const api = { onPick: null, onShip: null, mounted: false };
const pickables = () => { const a = [...planets.values()].map(p => p.hit); if (ship) a.push(ship.hit); return a; };
const focusObj = () => focusName === SHIP ? ship : planets.get(focusName);

function pu(extra) { return Object.assign({ uTime: uT, uLight: uSun }, extra); }
const dmgU = () => ({ uHurt: { value: 0 }, uDmgA: { value: Array.from({ length: 6 }, () => new THREE.Vector4()) }, uDmgB: { value: Array.from({ length: 6 }, () => new THREE.Vector4()) } });

function createPlanet(item, i) {
  const h = hash(item.name), rg = rnd(h), seed = rg();
  const type = [0, 1, 5, 4, 2, 0, 1, 5][h % 8];
  const tintC = new THREE.Color().setHSL((h % 360) / 360, .5, .56);
  const baseAtm = [new THREE.Vector3(.3, .6, 1), tintC.clone().lerp(new THREE.Color(1, 1, 1), .4), new THREE.Vector3(1, .45, .15), null, new THREE.Vector3(.6, .85, 1), new THREE.Vector3(.95, .62, .35)];
  const atm = type === 1 ? new THREE.Vector3(tintC.r, tintC.g, tintC.b).multiplyScalar(1.3) : baseAtm[type].clone ? baseAtm[type].clone() : new THREE.Vector3(.5, .7, 1);

  const pivot = new THREE.Group(); pivot.rotation.set((rg() - .5) * .5, 0, (rg() - .5) * .5); scene.add(pivot);
  const orbitLine = new THREE.LineLoop(
    new THREE.BufferGeometry().setFromPoints(Array.from({ length: 160 }, (_, k) => new THREE.Vector3(Math.cos(k / 160 * 6.2832), 0, Math.sin(k / 160 * 6.2832)))),
    new THREE.LineBasicMaterial({ color: 0x8c96ff, transparent: true, opacity: .16, depthWrite: false }));
  pivot.add(orbitLine);
  const holder = new THREE.Group(); pivot.add(holder);
  const body = new THREE.Group(); holder.add(body);
  const tilt = new THREE.Group(); tilt.rotation.z = (rg() - .5) * .8; body.add(tilt);

  const uni = pu({ uSeed: { value: seed }, uType: { value: type }, uDim: { value: 1 }, uLights: { value: 1 }, uHot: { value: 0 }, uTint: { value: new THREE.Vector3(tintC.r, tintC.g, tintC.b) }, uAtm: { value: atm }, ...dmgU() });
  const mesh = new THREE.Mesh(new THREE.SphereGeometry(1, 96, 64), new THREE.ShaderMaterial({ vertexShader: PLANET_VS, fragmentShader: PLANET_FS, uniforms: uni }));
  tilt.add(mesh);

  let clouds = null;
  if (type === 0 || type === 5) {
    clouds = new THREE.Mesh(new THREE.SphereGeometry(1.018, 64, 48), new THREE.ShaderMaterial({
      vertexShader: PLANET_VS, fragmentShader: CLOUD_FS, transparent: true, depthWrite: false,
      uniforms: pu({ uSeed: { value: seed } }) }));
    tilt.add(clouds);
  }
  const atmU = { uCol: { value: new THREE.Color('#4ade80') }, uLight: uSun, uRatio: { value: 1.16 }, uPow: { value: 2 }, uAmt: { value: .8 } };
  const shell = new THREE.Mesh(new THREE.SphereGeometry(1.16, 64, 48), new THREE.ShaderMaterial({
    vertexShader: ATM_VS, fragmentShader: ATM_FS, uniforms: atmU, side: THREE.BackSide, transparent: true, depthWrite: false, blending: THREE.AdditiveBlending }));
  body.add(shell);

  let ring = null;
  if (type === 1 ? (h % 4 !== 0) : (h % 7 === 0)) {
    const rin = 1.4 + rg() * .12, rout = rin + .42 + rg() * .32;
    const ru = pu({ uIn: { value: rin }, uOut: { value: rout }, uSeed: { value: seed }, uR: { value: 1 }, uTint: { value: new THREE.Vector3(tintC.r * .9 + .15, tintC.g * .85 + .12, tintC.b * .8 + .1) }, uPC: { value: new THREE.Vector3() } });
    ring = new THREE.Mesh(new THREE.RingGeometry(rin, rout, 160, 1), new THREE.ShaderMaterial({ vertexShader: RING_VS, fragmentShader: RING_FS, uniforms: ru, side: THREE.DoubleSide, transparent: true, depthWrite: false }));
    ring.rotation.x = -Math.PI / 2 + .12;
    tilt.add(ring);
  }

  const beacon = new THREE.Sprite(new THREE.SpriteMaterial({ map: beaconTex, color: 0xfbbf24, transparent: true, opacity: 0, depthWrite: false, blending: THREE.AdditiveBlending }));
  body.add(beacon);
  const glow = new THREE.Sprite(new THREE.SpriteMaterial({ map: glowTex, color: 0xfbbf24, transparent: true, opacity: .0, depthWrite: false, blending: THREE.AdditiveBlending }));
  glow.scale.setScalar(5.2); body.add(glow);

  const hit = new THREE.Mesh(new THREE.SphereGeometry(1.5, 12, 8), new THREE.MeshBasicMaterial({ visible: false }));
  body.add(hit);

  const btn = document.createElement('button'); btn.className = 'glabel';
  const b = document.createElement('b'), sp = document.createElement('span'); btn.append(b, sp);
  btn.onclick = () => api.onPick && api.onPick(item.name);
  btn.onpointerenter = () => { const p = planets.get(item.name); if (p) p.hl = true; };
  btn.onpointerleave = () => { const p = planets.get(item.name); if (p) p.hl = false; };
  lblBox.append(btn);

  const p = { name: item.name, type, seed, pivot, orbitLine, holder, body, tilt, mesh, clouds, shell, ring, beacon, glow, hit, uni, atmU, btn, b, sp,
    a: i * 2.39996 + rg() * .5, r: 20, rT: 20, R: 1.6, RT: 1.6, spin: .05 + rg() * .09, col: new THREE.Color('#6f6f7c'), st: 'offline', hot: 0, hl: false, moons: new Map(), dim: 1, lights: 0, atm, bursts: [], nextBurst: rand(.2, 1.2), lastBurst: 0,
    scars: [], impacts: [], kick: new THREE.Vector3(), kickV: new THREE.Vector3(), hurt: 0, hitUntil: 0 };
  hit.userData.planet = p; mesh.userData.planet = p;
  return p;
}

function syncMoons(p, users) {
  const seen = new Set();
  users.forEach((u, idx) => {
    seen.add(u.name);
    let m = p.moons.get(u.name);
    if (!m) {
      const h = hash(u.name), rg = rnd(h);
      const col = new THREE.Color(u.color);
      const g = new THREE.Group(); g.rotation.set((rg() - .5) * .9, 0, (rg() - .5) * .9); p.body.add(g);
      const ml = new THREE.LineLoop(
        new THREE.BufferGeometry().setFromPoints(Array.from({ length: 96 }, (_, k) => new THREE.Vector3(Math.cos(k / 96 * 6.2832), 0, Math.sin(k / 96 * 6.2832)))),
        new THREE.LineBasicMaterial({ color: col, transparent: true, opacity: .0, depthWrite: false }));
      g.add(ml);
      const mu = pu({ uSeed: { value: rg() }, uType: { value: 3 }, uDim: { value: 1 }, uLights: { value: 0 }, uHot: { value: 0 }, uTint: { value: new THREE.Vector3(col.r, col.g, col.b) }, uAtm: { value: new THREE.Vector3(col.r, col.g, col.b) }, ...dmgU() });
      const mesh = new THREE.Mesh(new THREE.SphereGeometry(.2, 32, 24), new THREE.ShaderMaterial({ vertexShader: PLANET_VS, fragmentShader: PLANET_FS, uniforms: mu }));
      const glow = new THREE.Sprite(new THREE.SpriteMaterial({ map: glowTex, color: col, transparent: true, opacity: .7, depthWrite: false, blending: THREE.AdditiveBlending }));
      glow.scale.setScalar(1.2);
      const holder = new THREE.Group(); holder.add(mesh, glow); g.add(holder);
      m = { name: u.name, g, ml, holder, mesh, glow, a: rg() * 6.2832, r: 1.9, rT: 1.9, st: 'offline' };
      p.moons.set(u.name, m);
    }
    m.st = u.st; m.rT = 1.95 + idx * .55;
  });
  for (const [k, m] of p.moons) if (!seen.has(k)) { p.body.remove(m.g); p.moons.delete(k); }
}

const burstCol = new THREE.Vector3(1, .6, .14);
function spawnBurst(p, big) {
  if (REDUCE || !p.body.parent || p.bursts.length >= 7 || tScene - p.lastBurst < .12) return;
  p.lastBurst = tScene;
  // kameraya görünən yarımkürədə təsadüfi nöqtə
  const toCam = p.body.worldToLocal(camera.position.clone()).normalize();
  const dir = toCam.clone().add(new THREE.Vector3(rand(-1, 1), rand(-1, 1), rand(-1, 1)).multiplyScalar(.95)).normalize();
  const N = big ? 200 : 100;
  const pos = new Float32Array(N * 3), vel = new Float32Array(N * 3), life = new Float32Array(N), size = new Float32Array(N);
  for (let i = 0; i < N; i++) {
    pos.set([dir.x, dir.y, dir.z], i * 3);
    const d = dir.clone().add(new THREE.Vector3(rand(-1, 1), rand(-1, 1), rand(-1, 1)).multiplyScalar(rand(.15, .75))).normalize();
    d.multiplyScalar(rand(.5, big ? 3.1 : 2.3));
    vel.set([d.x, d.y, d.z], i * 3);
    life[i] = rand(.7, big ? 1.9 : 1.5); size[i] = rand(.04, big ? .15 : .11);
  }
  const g = new THREE.BufferGeometry();
  g.setAttribute('position', new THREE.BufferAttribute(pos, 3)); g.setAttribute('vel', new THREE.BufferAttribute(vel, 3));
  g.setAttribute('life', new THREE.BufferAttribute(life, 1)); g.setAttribute('size', new THREE.BufferAttribute(size, 1));
  const mat = new THREE.ShaderMaterial({ vertexShader: BURST_VS, fragmentShader: BURST_FS, transparent: true, depthWrite: false, blending: THREE.AdditiveBlending,
    uniforms: { uAge: { value: 0 }, uPx: { value: 1 }, uG: { value: 1.1 }, uCol: { value: burstCol } } });
  const pts = new THREE.Points(g, mat); pts.frustumCulled = false;
  const flash = new THREE.Sprite(new THREE.SpriteMaterial({ map: glowTex, color: 0xffc070, transparent: true, depthWrite: false, blending: THREE.AdditiveBlending }));
  flash.position.copy(dir).multiplyScalar(1.04);
  const ring = new THREE.Mesh(new THREE.PlaneGeometry(1, 1), new THREE.MeshBasicMaterial({ map: beaconTex, color: 0xffb060, transparent: true, depthWrite: false, blending: THREE.AdditiveBlending, side: THREE.DoubleSide }));
  ring.position.copy(dir).multiplyScalar(1.02); ring.quaternion.setFromUnitVectors(new THREE.Vector3(0, 0, 1), dir);
  p.body.add(pts, flash, ring);
  p.bursts.push({ t0: tScene, pts, g, mat, flash, ring, big, maxLife: big ? 1.95 : 1.6 });
}
function updateBursts(p) {
  const pxu = H * pr / (2 * Math.tan(camera.fov * Math.PI / 360)) * p.R;
  for (let i = p.bursts.length - 1; i >= 0; i--) {
    const b = p.bursts[i], age = tScene - b.t0;
    if (age > b.maxLife) {
      p.body.remove(b.pts, b.flash, b.ring);
      b.g.dispose(); b.mat.dispose(); b.flash.material.dispose(); b.ring.geometry.dispose(); b.ring.material.dispose();
      p.bursts.splice(i, 1); continue;
    }
    b.mat.uniforms.uAge.value = age; b.mat.uniforms.uPx.value = pxu;
    const fk = Math.min(1, age / .5), rk = Math.min(1, age / .85);
    b.flash.material.opacity = Math.pow(1 - fk, 2) * .95;
    b.flash.scale.setScalar((b.big ? 1.1 : .8) * (.4 + .9 * Math.sqrt(fk)));
    b.ring.material.opacity = Math.pow(1 - rk, 1.5) * .8;
    b.ring.scale.setScalar(.12 + (b.big ? 1.05 : .75) * Math.sqrt(rk));
  }
}
api.burst = function (name, big) { const p = planets.get(name); if (p) spawnBurst(p, !!big); };

/* ====== Mesaj raketi: göndərənin planetindən alanın planetinə uçur, partlayır, planet zədələnir ====== */
const rockets = [], appliedHits = new Set();
let shake = 0, cine = null, kfl = null;
const shakeOff = new THREE.Vector3(), UPV = new THREE.Vector3(0, 1, 0);
const COL_FIRE = new THREE.Vector3(1, .55, .14), COL_SPARK = new THREE.Vector3(1, .9, .6), COL_DEB = new THREE.Vector3(.3, .21, .15), COL_SMOKE = new THREE.Vector3(.13, .115, .11);

/** WebAudio ilə sintez olunan səslər (fayl lazım deyil); səs söndürülübsə və ya brauzer icazə verməyibsə susur */
function audioCtx() {
  if (!sfx.enabled || document.hidden) return null;
  try {
    if (!sfx.ctx) sfx.ctx = new (window.AudioContext || window.webkitAudioContext)();
    if (sfx.ctx.state === 'suspended') sfx.ctx.resume();
    return sfx.ctx.state === 'running' ? sfx.ctx : null;
  } catch (e) { return null; }
}
let noiseBuf = null;
function noiseBuffer(c) {
  if (!noiseBuf || noiseBuf.sampleRate !== c.sampleRate) {
    const n = Math.floor(c.sampleRate * 2.2); noiseBuf = c.createBuffer(1, n, c.sampleRate);
    const d = noiseBuf.getChannelData(0); for (let i = 0; i < n; i++) d[i] = Math.random() * 2 - 1;
  }
  return noiseBuf;
}
function sfxOut(c, vol, pan) {
  const out = c.createGain(); out.gain.value = vol;
  if (c.createStereoPanner) { const pn = c.createStereoPanner(); pn.pan.value = pan; out.connect(pn); pn.connect(c.destination); } else out.connect(c.destination);
  return out;
}
function boom(vol, pan) {
  const c = audioCtx(); if (!c || vol < .02) return;
  const t = c.currentTime, out = sfxOut(c, Math.min(1, vol), pan);
  const src = c.createBufferSource(); src.buffer = noiseBuffer(c);
  const lp = c.createBiquadFilter(); lp.type = 'lowpass'; lp.frequency.setValueAtTime(2600, t); lp.frequency.exponentialRampToValueAtTime(60, t + 1.8);
  const g = c.createGain(); g.gain.setValueAtTime(.0001, t); g.gain.exponentialRampToValueAtTime(1, t + .02); g.gain.exponentialRampToValueAtTime(.0001, t + 2);
  src.connect(lp); lp.connect(g); g.connect(out); src.start(t); src.stop(t + 2.1);
  const o = c.createOscillator(); o.type = 'sine'; o.frequency.setValueAtTime(120, t); o.frequency.exponentialRampToValueAtTime(26, t + 1);
  const og = c.createGain(); og.gain.setValueAtTime(.0001, t); og.gain.exponentialRampToValueAtTime(1.2, t + .015); og.gain.exponentialRampToValueAtTime(.0001, t + 1.2);
  o.connect(og); og.connect(out); o.start(t); o.stop(t + 1.3);
}
function whoosh(vol, pan) {
  const c = audioCtx(); if (!c || vol < .02) return;
  const t = c.currentTime, out = sfxOut(c, Math.min(1, vol), pan);
  const src = c.createBufferSource(); src.buffer = noiseBuffer(c);
  const bp = c.createBiquadFilter(); bp.type = 'bandpass'; bp.Q.value = .9; bp.frequency.setValueAtTime(260, t); bp.frequency.exponentialRampToValueAtTime(1500, t + 1.4);
  const g = c.createGain(); g.gain.setValueAtTime(.0001, t); g.gain.exponentialRampToValueAtTime(.7, t + .35); g.gain.exponentialRampToValueAtTime(.0001, t + 1.6);
  src.connect(bp); bp.connect(g); g.connect(out); src.start(t); src.stop(t + 1.7);
}

function mkPts(dir, N, o) {
  const pos = new Float32Array(N * 3), vel = new Float32Array(N * 3), life = new Float32Array(N), size = new Float32Array(N);
  for (let i = 0; i < N; i++) {
    pos.set([dir.x, dir.y, dir.z], i * 3);
    const d = dir.clone().add(new THREE.Vector3(rand(-1, 1), rand(-1, 1), rand(-1, 1)).multiplyScalar(o.spread)).normalize().multiplyScalar(rand(o.v0, o.v1));
    vel.set([d.x, d.y, d.z], i * 3);
    life[i] = rand(o.l0, o.l1); size[i] = rand(o.s0, o.s1);
  }
  const g = new THREE.BufferGeometry();
  g.setAttribute('position', new THREE.BufferAttribute(pos, 3)); g.setAttribute('vel', new THREE.BufferAttribute(vel, 3));
  g.setAttribute('life', new THREE.BufferAttribute(life, 1)); g.setAttribute('size', new THREE.BufferAttribute(size, 1));
  const mat = new THREE.ShaderMaterial({ vertexShader: BURST_VS, fragmentShader: BURST_FS, transparent: true, depthWrite: false,
    blending: o.add ? THREE.AdditiveBlending : THREE.NormalBlending, uniforms: { uAge: { value: 0 }, uPx: { value: 1 }, uG: { value: o.g }, uCol: { value: o.col } } });
  const pts = new THREE.Points(g, mat); pts.frustumCulled = false;
  return { pts, g, mat };
}
/** Böyük partlayış: alov, qığılcım, qaya parçaları, tüstü, işıq çaxnaşı və iki zərbə halqası */
function spawnImpact(p, dir) {
  if (p.impacts.length >= 3) disposeImpact(p, p.impacts.shift());
  const parts = [
    mkPts(dir, 380, { add: true, g: 1.1, col: COL_FIRE, spread: .9, v0: .6, v1: 4.6, l0: .8, l1: 2.3, s0: .05, s1: .2 }),
    mkPts(dir, 120, { add: true, g: .5, col: COL_SPARK, spread: .35, v0: 2.5, v1: 6.5, l0: .5, l1: 1.4, s0: .03, s1: .08 }),
    mkPts(dir, 46, { add: false, g: .7, col: COL_DEB, spread: .8, v0: 1.2, v1: 4.4, l0: 1.6, l1: 3.2, s0: .09, s1: .22 }),
    mkPts(dir, 60, { add: false, g: .08, col: COL_SMOKE, spread: .6, v0: .15, v1: .9, l0: 2.6, l1: 4.4, s0: .3, s1: .65 }),
  ];
  const spr = (hex, k) => { const s = new THREE.Sprite(new THREE.SpriteMaterial({ map: glowTex, color: hex, transparent: true, depthWrite: false, blending: THREE.AdditiveBlending })); s.position.copy(dir).multiplyScalar(k); return s; };
  const flash = spr(0xffd9a0, 1.04), shock = spr(0xff9a50, 1.02);
  const rings = [0, 1].map(() => {
    const r = new THREE.Mesh(new THREE.PlaneGeometry(1, 1), new THREE.MeshBasicMaterial({ map: beaconTex, color: 0xffa860, transparent: true, depthWrite: false, blending: THREE.AdditiveBlending, side: THREE.DoubleSide }));
    r.position.copy(dir).multiplyScalar(1.02); r.quaternion.setFromUnitVectors(new THREE.Vector3(0, 0, 1), dir); return r;
  });
  const fx = { t0: tScene, parts, flash, shock, rings, maxLife: 4.8 };
  p.body.add(...parts.map(q => q.pts), flash, shock, ...rings);
  p.impacts.push(fx);
}
function disposeImpact(p, f) {
  p.body.remove(...f.parts.map(q => q.pts), f.flash, f.shock, ...f.rings);
  for (const q of f.parts) { q.g.dispose(); q.mat.dispose(); }
  f.flash.material.dispose(); f.shock.material.dispose();
  for (const r of f.rings) { r.geometry.dispose(); r.material.dispose(); }
}
function updateImpacts(p) {
  const pxu = H * pr / (2 * Math.tan(camera.fov * Math.PI / 360)) * p.R;
  for (let i = p.impacts.length - 1; i >= 0; i--) {
    const f = p.impacts[i], age = tScene - f.t0;
    if (age > f.maxLife) { disposeImpact(p, f); p.impacts.splice(i, 1); continue; }
    for (const q of f.parts) { q.mat.uniforms.uAge.value = age; q.mat.uniforms.uPx.value = pxu; }
    const fk = Math.min(1, age / .55), sk = Math.min(1, age / 1);
    f.flash.material.opacity = Math.pow(1 - fk, 2); f.flash.scale.setScalar(2 + 6 * Math.sqrt(fk));
    f.shock.material.opacity = Math.pow(1 - sk, 1.6) * .55; f.shock.scale.setScalar(2.5 + 11 * Math.sqrt(sk));
    f.rings.forEach((r, j) => {
      const a = age - j * .13, rk = Math.min(1, Math.max(0, a) / 1.1);
      r.material.opacity = a < 0 ? 0 : Math.pow(1 - rk, 1.4) * .85; r.scale.setScalar(.15 + (3.4 - j * .9) * Math.sqrt(rk));
    });
  }
}

/** Planetdə qalan iz: krater + çatlar. 1 dəq tam qalır, sonra ~2,5 dəqiqəyə sağalır. */
function addScar(p, dirLocal, age, seed) {
  if (p.scars.length >= 6) p.scars.shift();
  p.scars.push({ dir: dirLocal.clone(), t0: tScene - age, rad: .34 + (seed % 7) * .012, seed: (seed % 97) / 10 });
}
function updateScars(p, dt) {
  const A = p.uni.uDmgA.value, B = p.uni.uDmgB.value;
  for (let i = p.scars.length - 1; i >= 0; i--) if (tScene - p.scars[i].t0 > 215) p.scars.splice(i, 1);
  for (let i = 0; i < 6; i++) {
    const s = p.scars[i];
    if (!s) { A[i].w = 0; continue; }
    const age = Math.max(0, tScene - s.t0), sc = age < 60 ? 1 : Math.max(0, 1 - (age - 60) / 150);
    A[i].set(s.dir.x, s.dir.y, s.dir.z, sc);
    B[i].set(Math.exp(-age / 10) * (.85 + .15 * Math.sin(age * 9)), s.seed, s.rad * (.3 + .7 * (1 - Math.exp(-age * 7))), 0);
  }
  p.hurt = Math.max(0, p.hurt - dt / 18);
  p.uni.uHurt.value = Math.pow(p.hurt, 1.2);
  const hit = tScene < p.hitUntil ? '1' : '';
  if (p.btn.dataset.hit !== hit) p.btn.dataset.hit = hit;
}
/** Yeni açılan səhifədə son ~3 dəqiqədəki zərbələrin izləri görünsün (raket oynamadan) */
api.syncHits = function (items) {
  if (!api.mounted) return;
  if (appliedHits.size > 400) appliedHits.clear();
  for (const h of items || []) {
    const key = h.id + ':' + h.project;
    if (appliedHits.has(key)) continue;
    const p = planets.get(h.project); if (!p) continue;
    appliedHits.add(key);
    const rg = rnd(h.id * 7919 + hash(h.project)), z = rg() * 2 - 1, a = rg() * 6.2832, s = Math.sqrt(1 - z * z);
    addScar(p, new THREE.Vector3(s * Math.cos(a), z, s * Math.sin(a)), Math.max(0, +h.age || 0), h.id);
    p.hurt = Math.max(p.hurt, Math.max(0, 1 - (+h.age || 0) / 18));
  }
};

function screenFlash(x, y, k) {
  if (!kfl || REDUCE || !kfl.animate) return;
  kfl.style.setProperty('--fx', ((x * .5 + .5) * 100).toFixed(1) + '%'); kfl.style.setProperty('--fy', ((-y * .5 + .5) * 100).toFixed(1) + '%');
  kfl.animate([{ opacity: k }, { opacity: 0 }], { duration: 700, easing: 'cubic-bezier(.23,1,.32,1)' });
}
const closeness = (d, R) => Math.min(1, Math.max(0, 1 - (d - R * 4) / (R * 30)));

function makeRocket(hue) {
  const g = new THREE.Group(), L = 2, rx = geo => geo.rotateX(Math.PI / 2);
  const band = new THREE.Color().setHSL(hue / 360, .7, .55).getHex();
  g.add(
    fm(rx(cyl(.3, .3, L * .62, 18)), 0xe9edf6, 0),
    fm(rx(new THREE.ConeGeometry(.3, L * .38, 18)), 0xef4444, 0, 0, 0, L * .5),
    fm(rx(cyl(.314, .314, .24, 18)), band, .3, 0, 0, L * .06),
    fm(new THREE.SphereGeometry(.12, 10, 8), 0x7dd3fc, .5, 0, .24, L * .2),
    fm(rx(cyl(.2, .26, .16, 14)), 0x3a3f4d, 0, 0, 0, -L * .34));
  for (let k = 0; k < 4; k++) {
    const fg = new THREE.Group(); fg.rotation.z = k * Math.PI / 2;
    fg.add(fm(new THREE.BoxGeometry(.05, .34, .5), 0xef4444, 0, 0, .3, -L * .28)); g.add(fg);
  }
  const flame = new THREE.Mesh(rx(new THREE.ConeGeometry(.22, 1.7, 12)).rotateX(Math.PI), new THREE.MeshBasicMaterial({ color: 0xffa24a, transparent: true, opacity: .9, depthWrite: false, blending: THREE.AdditiveBlending }));
  flame.position.set(0, 0, -L * .34 - .85);
  const core = new THREE.Mesh(rx(new THREE.ConeGeometry(.12, 1, 10)).rotateX(Math.PI), new THREE.MeshBasicMaterial({ color: 0xfff3d0, transparent: true, opacity: .95, depthWrite: false, blending: THREE.AdditiveBlending }));
  core.position.set(0, 0, -L * .34 - .5);
  const glow = new THREE.Sprite(new THREE.SpriteMaterial({ map: glowTex, color: 0xffa860, transparent: true, depthWrite: false, blending: THREE.AdditiveBlending, opacity: .85 }));
  glow.scale.setScalar(4.2); glow.position.set(0, 0, -L * .4);
  g.add(flame, core, glow);
  g.userData = { flame, core, glow };
  return g;
}
const TN = 72;
function makeTrail(cool, life) {
  const pos = new Float32Array(TN * 3), born = new Float32Array(TN).fill(-1e4), sz = new Float32Array(TN).fill(.5);
  const g = new THREE.BufferGeometry();
  g.setAttribute('position', new THREE.BufferAttribute(pos, 3)); g.setAttribute('aBorn', new THREE.BufferAttribute(born, 1)); g.setAttribute('aSz', new THREE.BufferAttribute(sz, 1));
  const mat = new THREE.ShaderMaterial({ vertexShader: TRAIL_VS, fragmentShader: TRAIL_FS, transparent: true, depthWrite: false, blending: THREE.AdditiveBlending,
    uniforms: { uNow: uT, uLife: { value: life || 1.3 }, uPx: { value: 1 }, uCool: { value: cool || 0 } } });
  const pts = new THREE.Points(g, mat); pts.frustumCulled = false;
  return { pts, g, mat, i: 0 };
}
function trailAdd(t, v, size) {
  const k = t.i++ % TN, a = t.g.attributes;
  a.position.setXYZ(k, v.x, v.y, v.z); a.aBorn.setX(k, tScene); a.aSz.setX(k, size);
  a.position.needsUpdate = a.aBorn.needsUpdate = a.aSz.needsUpdate = true;
}

/** o: {id, from, to, hue, who, delay, cinema:'chase'|'wide'}. Qaytarır: zərbəyə qədər saniyə (0 = raket oynanmır) */
api.rocket = function (o) {
  if (!api.mounted || REDUCE) return 0;
  const pt = planets.get(o.to); if (!pt) return 0;
  const pf = o.from ? planets.get(o.from) || null : null;
  const C = pf ? pf.holder.getWorldPosition(new THREE.Vector3()) : new THREE.Vector3(), T = pt.holder.getWorldPosition(new THREE.Vector3());
  const same = pf === pt, dist = same ? pt.R * 8 : C.distanceTo(T);
  const r = { id: o.id, who: o.who || '', hue: o.hue || 0, pf, pt, same, t0: tScene + (o.delay || 0), dur: Math.min(5.2, Math.max(2.8, 2.4 + dist * .045)), dist,
    launched: false, done: false, tImpact: 0, emit: 0, camDir: new THREE.Vector3(), S: new THREE.Vector3(), E: new THREE.Vector3(), side: new THREE.Vector3(), cur: new THREE.Vector3(), lift: 0, g: null, trail: null, lbl: null };
  appliedHits.add(o.id + ':' + o.to);
  rockets.push(r);
  if (o.cinema) { if (cine && o.cinema === 'wide' && cine.mode === 'wide' && tScene - cine.t0 < 4) cine.rks.push(r); else startCine([r], o.cinema); }
  return r.dur + (o.delay || 0);
};
function launchRocket(r) {
  r.launched = true;
  const T = r.pt.holder.getWorldPosition(new THREE.Vector3());
  const C = r.pf ? r.pf.holder.getWorldPosition(new THREE.Vector3()) : new THREE.Vector3(), Rs = r.pf ? r.pf.R : 5.2;
  if (r.same) {
    r.S.copy(C).addScaledVector(UPV, Rs * 1.06);
    r.side.set(Math.cos(r.id * 1.7), 0, Math.sin(r.id * 1.7));
    r.lift = Rs * 6;
  } else {
    r.S.copy(C).addScaledVector(T.clone().sub(C).normalize(), Rs * 1.06);
    r.lift = Math.max(6, r.dist * .22);
  }
  r.g = makeRocket(r.hue); r.trail = makeTrail();
  r.g.position.copy(r.S); scene.add(r.g, r.trail.pts);
  r.lbl = document.createElement('div'); r.lbl.className = 'rkt'; r.lbl.textContent = '✉ ' + r.who; lblBox.append(r.lbl);
  if (r.pf) {
    spawnBurst(r.pf, true);
    const cl = closeness(camera.position.distanceTo(C), r.pf.R), nd = C.clone().project(camera);
    whoosh(.12 + .6 * cl, nd.z > 1 ? 0 : Math.max(-1, Math.min(1, nd.x)) * .85);
  }
}
function rocketPos(r, u, out) {
  out.copy(r.S).lerp(r.E, Math.pow(u, 1.55));
  out.y += r.lift * 4 * u * (1 - u);
  return out;
}
function disposeRocket(r) {
  if (r.g) { scene.remove(r.g); r.g.userData.flame.material.dispose(); r.g.userData.core.material.dispose(); r.g.userData.glow.material.dispose(); r.g.traverse(o => { if (o.isMesh && o.geometry) o.geometry.dispose(); }); }
  if (r.trail) { scene.remove(r.trail.pts); r.trail.g.dispose(); r.trail.mat.dispose(); }
  if (r.lbl) r.lbl.remove();
}
const rp = new THREE.Vector3(), rp0 = new THREE.Vector3(), rp1 = new THREE.Vector3(), rT = new THREE.Vector3(), rD = new THREE.Vector3();
function impactRocket(r, P, fwd) {
  const p = r.pt, C = p.holder.getWorldPosition(new THREE.Vector3());
  const probe = C.clone().add(P.clone().sub(C).normalize());
  addScar(p, p.mesh.worldToLocal(probe.clone()).normalize(), 0, r.id);
  spawnImpact(p, p.body.worldToLocal(probe.clone()).normalize());
  p.hurt = 1; p.hitUntil = tScene + 25;
  const inv = p.pivot.matrixWorld.clone().invert();
  p.kickV.addScaledVector(fwd.clone().transformDirection(inv), 5.4);
  const cl = closeness(camera.position.distanceTo(C), p.R), nd = C.clone().project(camera);
  shake = Math.max(shake, .3 + .7 * cl);
  boom(.25 + .7 * cl, nd.z > 1 ? 0 : Math.max(-1, Math.min(1, nd.x)) * .85);
  if (nd.z < 1) screenFlash(nd.x, nd.y, .2 + .6 * cl);
}
function updateRockets(dt) {
  const pxT = H * pr / (2 * Math.tan(camera.fov * Math.PI / 360));
  for (let i = rockets.length - 1; i >= 0; i--) {
    const r = rockets[i];
    if (planets.get(r.pt.name) !== r.pt) { disposeRocket(r); rockets.splice(i, 1); continue; }
    if (tScene < r.t0) continue;
    if (!r.launched) launchRocket(r);
    r.trail.mat.uniforms.uPx.value = pxT;
    if (!r.done) {
      const u = Math.min(1, (tScene - r.t0) / r.dur);
      r.pt.holder.getWorldPosition(rT);
      if (r.same) rD.copy(r.side);
      else {                                                    // zərbə nöqtəsi göndərənə baxan yarımkürədə, amma mümkün qədər kameraya tərəf (partlayış görünsün)
        rD.copy(r.S).sub(rT).normalize();
        rp0.copy(camera.position).sub(rT).normalize();
        if (u === 0 || r.camDir.lengthSq() < .5) r.camDir.copy(rp0); else r.camDir.lerp(rp0, Math.min(1, dt * 1.5)).normalize();
        rD.addScaledVector(r.camDir, .8).normalize();
      }
      r.E.copy(rT).addScaledVector(rD, r.pt.R * 1.05);
      rocketPos(r, u, rp); rocketPos(r, Math.min(1, u + .012), rp1); rocketPos(r, Math.max(0, u - .012), rp0);
      rD.copy(rp1).sub(rp0); if (rD.lengthSq() < 1e-9) rD.copy(UPV); rD.normalize();
      r.cur.copy(rp); r.g.position.copy(rp); r.g.lookAt(rp.x + rD.x, rp.y + rD.y, rp.z + rD.z);
      const sc = Math.max(1, camera.position.distanceTo(rp) * .026); r.g.scale.setScalar(sc);
      const ud = r.g.userData, fl = 1 + Math.sin(tScene * 63 + r.id) * .16 + Math.sin(tScene * 41) * .1;
      ud.flame.scale.set(1, 1, fl * (.85 + .3 * Math.min(1, u * 5))); ud.core.scale.set(1, 1, fl); ud.glow.material.opacity = .65 + .25 * Math.sin(tScene * 30);
      r.emit -= dt;
      if (r.emit <= 0) { r.emit = .02; trailAdd(r.trail, rp1.copy(rp).addScaledVector(rD, -1.1 * sc), .95 * sc); }
      applyLabel(r.lbl, labelPos(rp, .6 * sc, true));
      if (u >= 1) {
        impactRocket(r, rp, rD);
        r.done = true; r.tImpact = tScene; r.g.visible = false; r.lbl.remove(); r.lbl = null;
      }
    } else if (tScene - r.tImpact > 1.6) { disposeRocket(r); rockets.splice(i, 1); }
  }
}

/* ---- kinematoqrafik kamera: göndərən üçün raketi izləyir, sonra əvvəlki yerə qayıdır ---- */
function startCine(rks, mode) {
  const saved = cine ? cine.saved : { pos: camera.position.clone(), target: controls.target.clone(), focus: focusName, min: controls.minDistance, max: controls.maxDistance };
  cine = { mode, rks, saved, t0: tScene, dirv: null };
  userMoved = true; fly = null; zT = null;
  controls.minDistance = 8; controls.maxDistance = Math.max(controls.maxDistance, 420);
}
function endCine(restore) {
  if (!cine) return;
  const c = cine; cine = null;
  controls.minDistance = c.saved.min; controls.maxDistance = c.saved.max;
  if (!restore) return;
  if (c.saved.focus && focusName === c.saved.focus && focusObj()) { const p = focusObj(); startFly({ off: focusPose(p) }, 1.5, p); }
  else if (!focusName) startFly({ pos: c.saved.pos, target: c.saved.target }, 1.4);
}
const cA = new THREE.Vector3(), cB = new THREE.Vector3(), cT = new THREE.Vector3();
function cineStep(dt) {
  const k = 1 - Math.exp(-dt * 3.2), live = cine.rks.filter(r => planets.get(r.pt.name) === r.pt);
  if (!live.length || tScene - cine.t0 > 18 || (live.every(r => r.done) && tScene - Math.max(...live.map(r => r.tImpact)) > 3.2)) { endCine(true); return; }
  if (cine.mode === 'wide') {
    const o = overviewPose(); cA.copy(o.target); cB.copy(o.pos);
  } else {
    const r = live[0], T = r.pt.holder.getWorldPosition(cT);
    if (!cine.dirv) {
      const fv = new THREE.Vector3().copy(T).sub(r.pf ? r.pf.holder.getWorldPosition(new THREE.Vector3()) : new THREE.Vector3()); fv.y = 0;
      if (fv.lengthSq() < .01) fv.set(1, 0, 0);
      cine.dirv = new THREE.Vector3().crossVectors(fv.normalize(), UPV).multiplyScalar(.85).addScaledVector(UPV, .45).normalize();
    }
    let d;
    if (r.done) { cA.copy(T); d = r.pt.R * 10; }
    else if (!r.launched) { (r.pf ? r.pf.holder.getWorldPosition(cA) : cA.set(0, 0, 0)); d = Math.max(26, r.dist * .75); }
    else { const u = Math.min(1, (tScene - r.t0) / r.dur); cA.copy(r.cur).lerp(T, u * .55); d = Math.max(24, r.dist * .75) * (1 - u * .35); }
    cB.copy(cA).addScaledVector(cine.dirv, d);
  }
  camera.position.lerp(cB, k); controls.target.lerp(cA, k);
}


/* ====== Plan gəmisi: kainatda daima uçan böyük gəmi. Klikləyəndə proyektlərin planları və scope-ları açılır ====== */
const SHIP = '~ship';
let ship = null, hoveredShip = false, shipRs = 40;
const shipP = [new THREE.Vector3(), new THREE.Vector3()], shipWP = new THREE.Vector3(), shipTmp = new THREE.Vector3();
function shipPos(a, out) {
  const rr = shipRs * (1 + .16 * Math.sin(a * 2.3 + 1));
  return out.set(Math.cos(a) * rr, 15 + 6 * Math.sin(a * 1.7 + .5), Math.sin(a) * rr * .82);
}
function buildShip() {
  const g = new THREE.Group(), body = new THREE.Group(); g.add(body);
  const rx = geo => geo.rotateX(Math.PI / 2), add = (m, sx, sy, sz) => { if (sx) m.scale.set(sx, sy, sz); body.add(m); return m; };
  add(fm(new THREE.SphereGeometry(1, 32, 22), 0xcfd6e6, 0), 1.6, 1.15, 5.2);
  add(fm(new THREE.SphereGeometry(1, 24, 16), 0x59627f, 0, 0, -.5, 0), 1.64, .62, 4.7);
  add(fm(new THREE.SphereGeometry(1, 20, 14), 0x9db4de, 0, 0, 1.05, 1.5), .95, .75, 1.8);
  add(fm(new THREE.BoxGeometry(.9, .16, .6), 0x7dd3fc, 1.1, 0, 1.2, 2.9));
  add(fm(rx(new THREE.ConeGeometry(1, 3, 24)), 0xe9edf6, 0, 0, 0, 5.3));
  for (const sx of [-1, 1]) {
    const w = fm(new THREE.BoxGeometry(5.2, .22, 3), 0x56608a, 0, sx * 3.4, -.1, -1.2); w.rotation.z = -sx * .1; body.add(w);
    const st = fm(new THREE.BoxGeometry(5.2, .24, .45), 0xf59e0b, .25, sx * 3.4, -.1, -2.2); st.rotation.z = -sx * .1; body.add(st);
    body.add(fm(rx(cyl(.55, .7, 3.4, 16)), 0x3a3f4d, 0, sx * 1.9, -.1, -4.5));
    body.add(fm(rx(cyl(.5, .5, .1, 16)), 0x7dd3fc, 1.2, sx * 1.9, -.1, -6.25));
  }
  const fin = fm(new THREE.BoxGeometry(.2, 2.2, 2.4), 0x56608a, 0, 0, 1.7, -4); fin.rotation.x = -.3; body.add(fin);
  const ring = new THREE.Group(); ring.position.z = .6; body.add(ring);
  ring.add(fm(new THREE.TorusGeometry(3.2, .18, 10, 56), 0x7c86a8, 0));
  for (let k = 0; k < 14; k++) { const a = k / 14 * 6.2832; ring.add(fm(new THREE.SphereGeometry(.15, 8, 6), 0xffe9a8, 1.5, Math.cos(a) * 3.2, Math.sin(a) * 3.2, 0)); }
  for (let k = 0; k < 4; k++) { const a = k * 1.5708 + .4, sp = fm(cyl(.07, .07, 3.2, 6), 0x7c86a8, 0, Math.cos(a) * 1.6, Math.sin(a) * 1.6, 0); sp.rotation.z = a - Math.PI / 2; ring.add(sp); }
  for (let k = 0; k < 11; k++) {
    const z = -3 + k * .64, r = 1.6 * Math.sqrt(1 - (z / 5.2) ** 2) * .97;
    for (const sx of [-1, 1]) body.add(fm(new THREE.SphereGeometry(.1, 8, 6), 0xffe9a8, 1.6, sx * r, .28, z));
  }
  const nav = [];
  for (const [sx, hex] of [[-1, 0xff3030], [1, 0x30ff60]]) {
    const s = new THREE.Sprite(new THREE.SpriteMaterial({ map: glowTex, color: hex, transparent: true, depthWrite: false, blending: THREE.AdditiveBlending }));
    s.position.set(sx * 6, -.1, -1.2); s.scale.setScalar(1.8); body.add(s); nav.push(s);
  }
  const flames = [];
  for (const sx of [-1, 1]) {
    const f = new THREE.Mesh(rx(new THREE.ConeGeometry(.5, 3.4, 12)).rotateX(Math.PI), new THREE.MeshBasicMaterial({ color: 0x7fd8ff, transparent: true, opacity: .85, depthWrite: false, blending: THREE.AdditiveBlending }));
    f.position.set(sx * 1.9, -.1, -6.3 - 1.7);
    const c = new THREE.Mesh(rx(new THREE.ConeGeometry(.26, 2, 10)).rotateX(Math.PI), new THREE.MeshBasicMaterial({ color: 0xffffff, transparent: true, opacity: .95, depthWrite: false, blending: THREE.AdditiveBlending }));
    c.position.set(sx * 1.9, -.1, -6.3 - 1);
    const gl = new THREE.Sprite(new THREE.SpriteMaterial({ map: glowTex, color: 0x7fd8ff, transparent: true, opacity: .8, depthWrite: false, blending: THREE.AdditiveBlending }));
    gl.scale.setScalar(5); gl.position.set(sx * 1.9, -.1, -6.4);
    body.add(f, c, gl); flames.push({ f, c, gl, sx });
  }
  const halo = new THREE.Sprite(new THREE.SpriteMaterial({ map: glowTex, color: 0x6fb3ff, transparent: true, opacity: .13, depthWrite: false, blending: THREE.AdditiveBlending }));
  halo.scale.setScalar(30); body.add(halo);
  const hit = new THREE.Mesh(new THREE.SphereGeometry(8, 12, 8), new THREE.MeshBasicMaterial({ visible: false }));
  g.add(hit); hit.userData.ship = true;
  g.scale.setScalar(1.15); scene.add(g);

  const btn = document.createElement('button'); btn.className = 'glabel shipl';
  const b = document.createElement('b'), sp2 = document.createElement('span'); b.textContent = '🛸 Plan'; btn.append(b, sp2);
  btn.onclick = () => api.onShip && api.onShip();
  btn.onpointerenter = () => { if (ship) ship.hl = true; }; btn.onpointerleave = () => { if (ship) ship.hl = false; };
  lblBox.append(btn);
  ship = { name: SHIP, holder: g, body, ring, hit, btn, b, sp2, flames, nav, halo, RT: 7.5, R: 4.4, a: 1.2, sp: 1, hl: false, emit: 0,
    trails: [makeTrail(1, 2.8), makeTrail(1, 2.8)] };
  for (const t of ship.trails) scene.add(t.pts);
}
function updateShip(dt) {
  if (!ship) return;
  const foc = focusName === SHIP, want = foc ? .2 : (hoveredShip || ship.hl) ? .25 : 1;
  ship.sp += (want - ship.sp) * Math.min(1, dt * 3);
  shipRs += (Math.max(34, maxR * .62 + 8) - shipRs) * Math.min(1, dt);
  if (!REDUCE) ship.a += dt * .075 * ship.sp;
  const g = ship.holder, p0 = shipPos(ship.a, shipP[0]), p1 = shipPos(ship.a + .03, shipP[1]);
  g.position.copy(p0);
  g.lookAt(p1.x, p1.y, p1.z);
  ship.body.rotation.z = -.2 + Math.sin(ship.a * 3.1) * .06;          // yana əyilmə
  ship.body.rotation.x = Math.sin(ship.a * 1.9) * .03;
  ship.ring.rotation.z += dt * .5 * (REDUCE ? 0 : 1);
  const t = tScene;
  for (const fl of ship.flames) {
    const k = 1 + Math.sin(t * 47 + fl.sx) * .13 + Math.sin(t * 29) * .08;
    fl.f.scale.set(1, 1, k); fl.c.scale.set(1, 1, k); fl.gl.material.opacity = .65 + .2 * Math.sin(t * 22 + fl.sx);
  }
  ship.nav[0].material.opacity = ship.nav[1].material.opacity = Math.sin(t * 5) > .3 ? 1 : .15;
  ship.halo.material.opacity = .11 + .03 * Math.sin(t * 1.4);
  const pxT = H * pr / (2 * Math.tan(camera.fov * Math.PI / 360));
  ship.emit -= dt;
  if (ship.emit <= 0 && !REDUCE) {
    ship.emit = .04; g.updateMatrixWorld(true);
    ship.trails.forEach((tr, i) => trailAdd(tr, g.localToWorld(shipTmp.set((i ? 1 : -1) * 1.9, -.1, -6.6)), 1.5));
  }
  for (const tr of ship.trails) tr.mat.uniforms.uPx.value = pxT;
}

/* ====== Yallı: milli geyimdə rəqs edənlər ====== */
const figMats = new Map();
function figMat(hex, em) {
  const k = hex + ':' + (em || 0);
  if (!figMats.has(k)) figMats.set(k, new THREE.ShaderMaterial({ vertexShader: FIG_VS, fragmentShader: FIG_FS,
    uniforms: { uColor: { value: new THREE.Vector3(((hex >> 16) & 255) / 255, ((hex >> 8) & 255) / 255, (hex & 255) / 255) }, uLight: uSun, uEm: { value: em || 0 } } }));
  return figMats.get(k);
}
const fm = (geo, hex, em, x = 0, y = 0, z = 0) => { const m = new THREE.Mesh(geo, figMat(hex, em)); m.position.set(x, y, z); return m; };
const cyl = (rt, rb, h, seg = 16) => new THREE.CylinderGeometry(rt, rb, h, seg);
const SKINS = [0xe3b48f, 0xd4a07a, 0xc89066];
const CHOKHA = [0x1b2a4a, 0x2a2a2e, 0x4a3426, 0x33475c];
const PAPAKH = [0x2b1d14, 0x161616, 0x4b3a2a];
const SKIRTS = [[0xb3262e, 0xd9a441, 0x1f7a4d], [0x7a2a8c, 0xc64b8f, 0x2d6fb7], [0xd24a2a, 0xf0c64a, 0x2f8f9d]];
const VELVET = [0x4a1d6b, 0x12315e, 0x6b1530];
const KELAGAI = [0xf2d94e, 0xe94f37, 0xf5f5f0, 0x3bb3a6];
const GOLD = 0xd9a441;

function makeArm(sleeve, skin, side) {
  const p = new THREE.Group(); p.position.set(side * .1, .74, 0);
  p.add(fm(cyl(.028, .026, .26, 8), sleeve, 0, 0, -.13, 0), fm(new THREE.SphereGeometry(.032, 10, 8), skin, 0, 0, -.27, 0));
  return p;
}
function makeDancer(female, i) {
  const r = rnd(hash('d' + i) + (female ? 7 : 3)), pick = a => a[Math.floor(r() * a.length)];
  const g = new THREE.Group(), body = new THREE.Group(); g.add(body);
  const skin = pick(SKINS);
  let sleeve, skirt = null;
  if (female) {
    const sk = pick(SKIRTS); sleeve = pick(VELVET);
    skirt = new THREE.Group();                                   // qat-qat tuman
    [[.26, .2, 0, .16], [.22, .17, .16, .16], [.18, .14, .32, .16]].forEach(([rb, rt, y, h], k) => skirt.add(fm(cyl(rt, rb, h), sk[k], 0, 0, y + h / 2 + .02, 0)));
    skirt.add(fm(new THREE.TorusGeometry(.2, .012, 6, 24), GOLD, .35, 0, .18, 0)); skirt.children[3].rotation.x = Math.PI / 2;
    body.add(skirt);
    body.add(fm(cyl(.09, .115, .26), sleeve, 0, 0, .63, 0));       // arxalıq
    const belt = fm(new THREE.TorusGeometry(.108, .018, 6, 20), GOLD, .4, 0, .5, 0); belt.rotation.x = Math.PI / 2; body.add(belt);
    const trim = fm(new THREE.TorusGeometry(.092, .01, 6, 20), GOLD, .4, 0, .755, 0); trim.rotation.x = Math.PI / 2; body.add(trim);
    body.add(fm(new THREE.SphereGeometry(.075, 14, 12), skin, 0, 0, .86, 0));
    const kc = pick(KELAGAI);                                       // kələğayı
    body.add(fm(new THREE.SphereGeometry(.086, 14, 10, 0, Math.PI * 2, 0, 1.9), kc, .1, 0, .875, -.005));
    const tail = fm(new THREE.PlaneGeometry(.11, .24), kc, .1, 0, .76, -.085); tail.material = tail.material; tail.rotation.x = .25; body.add(tail);
    tail.material = new THREE.ShaderMaterial({ vertexShader: FIG_VS, fragmentShader: FIG_FS, side: THREE.DoubleSide,
      uniforms: { uColor: { value: new THREE.Vector3(((kc >> 16) & 255) / 255, ((kc >> 8) & 255) / 255, (kc & 255) / 255) }, uLight: uSun, uEm: { value: .1 } } });
  } else {
    const ch = pick(CHOKHA); sleeve = ch;
    body.add(fm(cyl(.1, .135, .3), ch, 0, 0, .64, 0));             // çuxa (üst)
    body.add(fm(cyl(.135, .235, .34), ch, 0, 0, .35, 0));          // qıvrımlı aşağı hissə
    const belt = fm(new THREE.TorusGeometry(.14, .016, 6, 20), GOLD, .4, 0, .5, 0); belt.rotation.x = Math.PI / 2; body.add(belt);
    for (const sx of [-.045, .045]) body.add(fm(new THREE.BoxGeometry(.022, .15, .012), 0xe8e2d0, .1, sx, .66, .1)); // qazırlar
    body.add(fm(cyl(.04, .04, .1, 8), 0x141414, 0, -.07, .06, 0), fm(cyl(.04, .04, .1, 8), 0x141414, 0, .07, .06, 0));
    body.add(fm(new THREE.SphereGeometry(.075, 14, 12), skin, 0, 0, .86, 0));
    const pk = pick(PAPAKH);                                        // papaq
    body.add(fm(cyl(.09, .082, .12, 14), pk, 0, 0, .97, 0), fm(cyl(.094, .094, .03, 14), pk, 0, 0, .915, 0));
  }
  const armL = makeArm(sleeve, skin, -1), armR = makeArm(sleeve, skin, 1);
  body.add(armL, armR);
  return { g, body, skirt, armL, armR, female, ph: r() * 6.28 };
}
function buildDance() {
  const root = new THREE.Group(), ring = new THREE.Group(); root.add(ring);
  const N = 8, rad = .6, list = [];
  for (let i = 0; i < N; i++) {
    const d = makeDancer(i % 2 === 1, i), a = i / N * Math.PI * 2;
    d.g.position.set(Math.cos(a) * rad, 0, Math.sin(a) * rad); d.g.rotation.y = Math.atan2(-Math.cos(a), -Math.sin(a));
    ring.add(d.g); list.push(d);
  }
  const shadow = new THREE.Mesh(new THREE.CircleGeometry(.85, 28), new THREE.MeshBasicMaterial({ color: 0x000000, transparent: true, opacity: .22, depthWrite: false }));
  shadow.rotation.x = -Math.PI / 2; shadow.position.y = .004; root.add(shadow);
  return { root, ring, list, k: 0, moving: false, n: new THREE.Vector3(0, 1, 0), t: 0, phi: 0 };
}
/* ====== Gitara çalan oğlan ====== */
const noteTex = new Map();
function noteTexture(ch, color) {
  const k = ch + color;
  if (!noteTex.has(k)) {
    const c = document.createElement('canvas'); c.width = c.height = 96;
    const g = c.getContext('2d'); g.font = 'bold 76px sans-serif'; g.textAlign = 'center'; g.textBaseline = 'middle';
    g.shadowColor = color; g.shadowBlur = 10; g.fillStyle = color; g.fillText(ch, 48, 52);
    const t = new THREE.CanvasTexture(c); t.colorSpace = THREE.SRGBColorSpace; noteTex.set(k, t);
  }
  return noteTex.get(k);
}
function buildGuitar() {
  const root = new THREE.Group(), face = new THREE.Group(), body = new THREE.Group();
  root.add(face); face.add(body);
  const skin = 0xe0ad88, shirt = 0x2fb5a8, jeans = 0x2f4f8a, wood = 0xb5651d;
  const legL = fm(cyl(.052, .046, .36, 10), jeans, 0, .07, .2, 0), legR = fm(cyl(.052, .046, .36, 10), jeans, 0, -.07, .2, 0);
  body.add(legL, legR, fm(new THREE.BoxGeometry(.075, .04, .12), 0xf2f2ee, 0, .07, .02, .03), fm(new THREE.BoxGeometry(.075, .04, .12), 0xf2f2ee, 0, -.07, .02, .03));
  body.add(fm(cyl(.1, .088, .3, 14), shirt, 0, 0, .52, 0));
  body.add(fm(new THREE.SphereGeometry(.076, 14, 12), skin, 0, 0, .87, 0));
  body.add(fm(new THREE.SphereGeometry(.083, 14, 10, 0, Math.PI * 2, 0, 1.75), 0x3a2418, 0, 0, .885, -.006));
  const armL = makeArm(shirt, skin, 1), armR = makeArm(shirt, skin, -1);
  body.add(armL, armR);
  const gt = new THREE.Group(); gt.position.set(0, .5, .12); gt.rotation.z = -1.1; body.add(gt);   // boyun sol ələ (+X) doğru
  const disc = (r, y, hex, em, h = .05) => { const m = fm(cyl(r, r, h, 20), hex, em, 0, y, 0); m.rotation.x = Math.PI / 2; return m; };
  gt.add(disc(.13, 0, wood, .12), disc(.095, .15, wood, .12));
  const hole = disc(.034, .04, 0x111111, 0, .006); hole.position.z = .028; gt.add(hole);
  gt.add(fm(new THREE.BoxGeometry(.034, .38, .02), 0xc9a06a, .05, 0, .38, 0), fm(new THREE.BoxGeometry(.026, .36, .008), 0x3b2a1a, 0, 0, .38, .012), fm(new THREE.BoxGeometry(.05, .075, .018), 0x3b2a1a, 0, 0, .6, 0));
  gt.add(fm(new THREE.BoxGeometry(.06, .012, .01), 0x222222, 0, 0, -.06, .03));
  const shadow = new THREE.Mesh(new THREE.CircleGeometry(.45, 24), new THREE.MeshBasicMaterial({ color: 0x000000, transparent: true, opacity: .22, depthWrite: false }));
  shadow.rotation.x = -Math.PI / 2; shadow.position.y = .004; root.add(shadow);
  const notes = [];
  ['♪', '♫', '♪', '♬', '♫'].forEach((ch, i) => {
    const col = ['#ff7ab6', '#ffd24d', '#5ce1e6', '#b79cff', '#7dff9b'][i];
    const sp = new THREE.Sprite(new THREE.SpriteMaterial({ map: noteTexture(ch, col), transparent: true, depthWrite: false }));
    sp.visible = false; root.add(sp); notes.push({ sp, age: i * .55, life: 2.6 + i * .1, sway: i * 1.7 });
  });
  return { root, face, body, legR, armL, armR, notes, k: 0, moving: false, n: new THREE.Vector3(0, 1, 0), t: 0, az: 0 };
}
function tickGuitar(d, dt, p) {
  d.t += dt;
  const ph = d.t * 9.5, beat = d.t * 4.7;
  d.body.position.y = Math.abs(Math.sin(beat)) * .012;
  d.body.rotation.z = Math.sin(beat) * .035;
  d.armR.rotation.set(-.55 + .26 * Math.sin(ph), 0, .3 + .07 * Math.cos(ph));       // vurma qolu
  d.armL.rotation.set(-.9, 0, .9 + .05 * Math.sin(beat));                           // boyun üstündə sol əl
  d.legR.rotation.x = -.18 * Math.max(0, Math.sin(beat));                           // ayağı ilə tempi tutur
  // tamaşaçıya (kameraya) tərəf dönür
  const lc = d.root.worldToLocal(camera.position.clone());
  const want = Math.atan2(lc.x, lc.z);
  d.face.rotation.y += Math.atan2(Math.sin(want - d.face.rotation.y), Math.cos(want - d.face.rotation.y)) * Math.min(1, dt * 2.5);
  for (const n of d.notes) {                                                         // yuxarı süzülən notlar
    n.age += dt;
    if (n.age > n.life) n.age = 0;
    const u = n.age / n.life;
    n.sp.visible = u > 0 && u < 1;
    n.sp.position.set(.28 + Math.sin(n.age * 2.2 + n.sway) * .12 + u * .25, .7 + u * 1.1, .12);
    n.sp.material.opacity = Math.sin(Math.min(1, u * 1.15) * Math.PI) * .95;
    n.sp.scale.setScalar(.2 + .08 * Math.sin(n.age * 6 + n.sway));
  }
}
function tickDance(d, dt) {
  d.t += dt;
  const e = Math.pow(.5 + .5 * Math.sin(d.t * .38 - 1.2), 1.4);
  d.phi += dt * (.28 + 1.1 * e);
  d.ring.rotation.y = d.phi;
  const step = d.t * (3.2 + 4.5 * e);
  d.list.forEach((q, i) => {
    const ph = step + i * .9 + q.ph * .15;
    q.body.position.y = Math.abs(Math.sin(ph)) * .045;
    q.body.rotation.z = Math.sin(ph) * .05;
    if (q.skirt) q.skirt.rotation.z = Math.sin(ph + 1) * .08;
    const lift = 1.35 + .45 * Math.sin(ph * .5 + i);
    q.armL.rotation.z = -lift; q.armR.rotation.z = lift;
    q.armL.rotation.y = .15 * Math.sin(ph); q.armR.rotation.y = -.15 * Math.sin(ph);
  });
}

/* ====== Çıxışlar: istifadəçi işləyəndə planetdə ====== */
const PERFS = { dance: { build: buildDance, tick: tickDance, scale: .34 }, guitar: { build: buildGuitar, tick: tickGuitar, scale: .42 } };
function placePerf(p, d) {
  const toCam = p.mesh.worldToLocal(camera.position.clone()).normalize();
  const up = new THREE.Vector3(0, 1, 0).applyQuaternion(p.mesh.getWorldQuaternion(new THREE.Quaternion()).invert());
  up.addScaledVector(toCam, -up.dot(toCam)).normalize();                  // "şimal": ekranda yuxarı
  const right = new THREE.Vector3().crossVectors(up, toCam).normalize();
  const dir = up.clone().multiplyScalar(Math.cos(d.az)).addScaledVector(right, Math.sin(d.az));
  d.n.copy(toCam).multiplyScalar(Math.cos(.62)).addScaledVector(dir, Math.sin(.62)).normalize();
  d.root.position.copy(d.n).multiplyScalar(.995);
  d.root.quaternion.setFromUnitVectors(new THREE.Vector3(0, 1, 0), d.n);
}
function updatePerf(p, dt) {
  p.perf = p.perf || {};
  let kmax = 0;
  for (const [kind, def] of Object.entries(PERFS)) {
    const want = [...p.moons.values()].some(m => PERF[m.name] === kind && m.st === 'working');
    let d = p.perf[kind];
    if (!want && !d) continue;
    if (want && !d) {
      d = p.perf[kind] = def.build();
      const other = kind === 'dance' ? 'guitar' : 'dance';              // ikisi birlikdə olsa yan-yana
      d.az = p.perf[other] && p.perf[other].k > .05 ? (kind === 'guitar' ? 1.3 : -1.3) : 0;
      p.mesh.add(d.root); placePerf(p, d);
    }
    let target = want ? 1 : 0;
    const toCam = p.mesh.worldToLocal(camera.position.clone()).normalize();
    if (d.k > .05 && d.n.dot(toCam) < .22) d.moving = true;               // kameradan çıxıbsa, görünən tərəfə köçür
    if (d.moving) { target = 0; if (d.k < .06) { placePerf(p, d); d.moving = false; } }
    d.k += (target - d.k) * Math.min(1, dt * 3.2);
    d.root.visible = d.k > .01;
    kmax = Math.max(kmax, d.k);
    if (!d.root.visible) continue;
    d.root.scale.setScalar(def.scale * (.1 + .9 * d.k));
    def.tick(d, dt, p);
  }
  p.danceK = kmax;
}

function setData(items) {
  items = items.slice(0, 8);
  const keep = new Set(items.map(x => x.name));
  for (const [k, p] of planets) if (!keep.has(k)) { scene.remove(p.pivot); p.btn.remove(); planets.delete(k); }
  items.forEach((it, i) => {
    let p = planets.get(it.name);
    if (!p) {
      p = createPlanet(it, i); planets.set(it.name, p);
      p.r = p.rT = slotR(i); p.R = p.RT = 2.5 + Math.min(2, Math.log2(1 + it.edits + it.tools / 4) * .34);
      if (pendingFocus === it.name) { const n = pendingFocus; pendingFocus = null; setTimeout(() => api.focus(n, true), 0); }
    }
    p.rT = slotR(i);
    p.offT = 2 * Math.PI * i / Math.max(items.length, 1);
    if (p.off === undefined) p.off = p.offT;
    p.RT = 2.5 + Math.min(2, Math.log2(1 + it.edits + it.tools / 4) * .34);
    p.st = it.st;
    setText(p.b, it.name); setText(p.sp, it.sub);
    p.btn.dataset.st = it.st; p.btn.dataset.alert = it.alert ? '1' : '';
    syncMoons(p, it.users);
  });
  const nm = Math.max(0, items.length - 1);
  const newMax = slotR(nm);
  if (Math.abs(newMax - maxR) > .1) {
    maxR = newMax;
    if (!focusName && !cine) { controls.maxDistance = Math.max(140, maxR * 4.5); if (!userMoved) overview(true); }
  }
}
function setText(n, t) { if (n.textContent !== t) n.textContent = t; }

function overviewPose() {
  const asp = W / H, d = Math.max(50, maxR * 1.5 + 12, maxR * 1.18 / (.414 * Math.max(asp, .5)));
  return { pos: new THREE.Vector3(0, d * .52, d * .86), target: new THREE.Vector3(0, 0, 0) };
}
function overview(instant) {
  const o = overviewPose();
  controls.minDistance = 10; controls.maxDistance = Math.max(140, maxR * 4.5);
  if (instant || REDUCE) { camera.position.copy(o.pos); controls.target.copy(o.target); fly = null; controls.enabled = true; }
  else startFly({ pos: o.pos, target: o.target }, 1.1);
}
function startFly(to, dur, follow) {
  fly = { t0: performance.now(), dur: (REDUCE ? .01 : dur) * 1000, fp: camera.position.clone(), ft: controls.target.clone(), to, follow: follow || null };
}
function focusPose(p) {
  p.holder.getWorldPosition(tmp);
  const toSun = tmp2.copy(tmp).multiplyScalar(-1).normalize();
  if (toSun.lengthSq() < .01) toSun.set(0, 0, 1);
  const right = new THREE.Vector3().crossVectors(new THREE.Vector3(0, 1, 0), toSun).normalize();
  const off = toSun.clone().multiplyScalar(.78).add(right.multiplyScalar(.5)).add(new THREE.Vector3(0, .26, 0)).normalize().multiplyScalar(p.RT * 6.2);
  return off;
}

api.focus = function (name, instant) {
  if (!api.mounted) { pendingFocus = name; return; }
  if (cine) endCine(true);
  if (!name) {
    if (focusName === null) return;
    focusName = null; overview(); return;
  }
  const p = name === SHIP ? ship : planets.get(name);
  if (!p) { pendingFocus = name; return; }
  if (focusName === name) return;
  focusName = name; userMoved = true;
  const off = focusPose(p);
  p.holder.getWorldPosition(tmp);
  controls.minDistance = p.RT * 1.7; controls.maxDistance = p.RT * 45;
  if (instant || REDUCE) { camera.position.copy(tmp).add(off); controls.target.copy(tmp); fly = null; controls.enabled = true; }
  else startFly({ off }, 1.5, p);
};
api.debug = () => ({ pr: +pr.toFixed(2), prMax, quality, base: +baseDt.toFixed(4), dist: +camera.position.distanceTo(controls.target).toFixed(2), enabled: controls.enabled, zoom: controls.enableZoom, min: controls.minDistance, max: +controls.maxDistance.toFixed(1), fly: !!fly, focus: focusName });
api.setQuality = q => {
  quality = q === 'high' ? 'high' : 'auto';
  prMax = Math.min(devicePixelRatio || 1, 2);
  if (api.mounted) setPR(quality === 'high' ? prMax : Math.min(pr, prMax));
};
/* ====== Səs: hər istifadəçinin öz səsi, o işləyəndə çalır; işlədiyi planetə baxanda ucalır ====== */
const sfx = { tracks: {}, enabled: true, blocked: false, ctx: null };
const VMIN = .05, VMAX = .95;
api.setAudio = (map, enabled) => {
  map = map || {};
  for (const [u, url] of Object.entries(map)) {
    const t = sfx.tracks[u];
    if (!t) sfx.tracks[u] = { url, el: null, vol: 0, failed: false };
    else if (t.url !== url) {            // istifadəçi yeni musiqi yüklədi: köhnə elementi at
      if (t.el) t.el.pause();
      Object.assign(t, { url, el: null, failed: false, panner: null, panFailed: false });
    }
  }
  for (const [u, t] of Object.entries(sfx.tracks)) if (!(u in map) && t.url) { if (t.el) t.el.pause(); t.url = ''; }
  if (enabled !== undefined) sfx.enabled = enabled !== false;
};
api.setSound = on => { sfx.enabled = !!on; sfx.blocked = false; if (!on) stopSound(); };
api.soundState = () => {
  const ts = Object.values(sfx.tracks);
  return { enabled: sfx.enabled, blocked: sfx.blocked, failed: ts.length > 0 && ts.every(t => t.failed),
    playing: ts.some(t => t.el && !t.el.paused), vol: +Math.max(0, ...ts.map(t => t.vol)).toFixed(2),
    tracks: Object.fromEntries(Object.entries(sfx.tracks).map(([u, t]) => [u, { playing: !!(t.el && !t.el.paused), vol: +t.vol.toFixed(2), prox: +(t.prox || 0).toFixed(2), pan: t.panner ? +t.panner.pan.value.toFixed(2) : null }])) };
};
function stopSound() { for (const t of Object.values(sfx.tracks)) { if (t.el) t.el.pause(); t.vol = 0; } }
/** Stereo yön: səs planetin ekrandakı mövqeyinə görə sola/sağa meyillənir (WebAudio, mümkünsə) */
function ensurePan(t) {
  if (t.panner || t.panFailed || !t.el) return;
  try {
    if (!sfx.ctx) sfx.ctx = new (window.AudioContext || window.webkitAudioContext)();
    const ctx = sfx.ctx;
    ctx.resume().then(() => {
      if (ctx.state !== 'running' || t.panner) { t.panFailed = ctx.state !== 'running'; return; }
      const src = ctx.createMediaElementSource(t.el), pan = ctx.createStereoPanner();
      src.connect(pan); pan.connect(ctx.destination); t.panner = pan;
    }).catch(() => { t.panFailed = true; });
  } catch (e) { t.panFailed = true; }
}
const sp = new THREE.Vector3();
function updateSound(dt) {
  const on = [];
  for (const [user, t] of Object.entries(sfx.tracks)) {
    const pl = [...planets.values()].filter(p => { const m = p.moons.get(user); return m && m.st === 'working'; });
    t.want = sfx.enabled && !t.failed && !!t.url && active && !document.hidden && pl.length > 0;
    t.prox = 0; t.panT = 0;
    if (t.want) {
      // ən yaxın planet: kamera nə qədər yaxındırsa, səs o qədər güclü
      let best = null, bd = 1e9;
      for (const p of pl) { p.holder.getWorldPosition(sp); const d = Math.max(camera.position.distanceTo(sp) - p.R, .1) + p.R; if (d < bd) { bd = d; best = { p, x: sp.clone() }; } }
      t.prox = Math.min(1, Math.pow((best.p.R * 6) / bd, 1.5));
      const ndc = best.x.project(camera);
      t.panT = ndc.z > 1 ? 0 : Math.max(-1, Math.min(1, ndc.x)) * .85;
      on.push(t);
    }
  }
  const mix = on.length > 1 ? 1 / Math.sqrt(on.length) : 1;                       // bir neçə səs eyni anda olsa, ümumi səs yığılmasın
  for (const t of Object.values(sfx.tracks)) {
    const target = t.want ? (VMIN + (VMAX - VMIN) * t.prox) * mix : 0;
    if (t.panner) t.panner.pan.value += (t.panT - t.panner.pan.value) * Math.min(1, dt * 6);
    t.vol += (target - t.vol) * Math.min(1, dt * (target > t.vol ? 2.2 : 4));
    if (t.want && !sfx.blocked && (!t.el || t.el.paused)) {
      if (!t.el) { t.el = new Audio(t.url); t.el.loop = true; t.el.preload = 'auto'; t.el.addEventListener('error', () => { t.failed = true; }); }
      t.el.volume = Math.max(0, Math.min(1, t.vol));
      const pr = t.el.play();
      if (pr && pr.then) pr.then(() => ensurePan(t));
      if (pr && pr.catch) pr.catch(() => { sfx.blocked = true; const un = () => { sfx.blocked = false; }; addEventListener('pointerdown', un, { once: true, capture: true }); addEventListener('keydown', un, { once: true, capture: true }); });
    }
    if (t.el) {
      t.el.volume = Math.max(0, Math.min(1, t.vol));
      if (!t.want && t.vol < .01 && !t.el.paused) t.el.pause();
    }
  }
}
addEventListener('visibilitychange', () => { if (document.hidden) stopSound(); });
api.zoom = f => { const cur = camera.position.distanceTo(controls.target); zT = Math.min(controls.maxDistance, Math.max(controls.minDistance, (zT === null ? cur : zT) * f)); };
api.setData = items => { if (api.mounted) setData(items); };
api.setShip = o => { if (ship && o) setText(ship.sp2, o.sub || ''); };
api.setActive = a => { active = a; if (!a) stopSound(); if (a) { last = performance.now(); resize(); } };

function resize() {
  if (!box) return;
  const r = box.getBoundingClientRect();
  const w = Math.max(1, Math.floor(r.width)), h = Math.max(1, Math.floor(r.height));
  if (w === W && h === H) return;
  W = w; H = h;
  renderer.setPixelRatio(pr);
  renderer.setSize(W, H, false);
  camera.aspect = W / H; camera.updateProjectionMatrix();
  if (api.mounted && !userMoved && !focusName) overview(true);
  stars.material.uniforms.uPR.value = pr;
}
function setPR(v) {
  pr = v; renderer.setPixelRatio(pr); renderer.setSize(W, H, false);
  stars.material.uniforms.uPR.value = pr;
}

function labelPos(wp, R, show) {
  tmp.copy(wp).project(camera);
  if (!show || tmp.z > 1 || tmp.z < -1) return null;
  const x = (tmp.x * .5 + .5) * W, y = (-tmp.y * .5 + .5) * H;
  const pxu = H / (2 * Math.tan(camera.fov * Math.PI / 360) * camera.position.distanceTo(wp));
  return { x, y: y + R * pxu * 1.3 + 8 };
}
function applyLabel(el, q) {
  if (!q) { el.style.visibility = 'hidden'; return; }
  el.style.visibility = 'visible';
  el.style.transform = `translate(${q.x.toFixed(1)}px,${q.y.toFixed(1)}px) translateX(-50%)`;
}

const wp = new THREE.Vector3(), sunWP = new THREE.Vector3(0, 0, 0);
function frame(now) {
  requestAnimationFrame(frame);
  if (!active || document.hidden) { last = now; return; }
  const dt = Math.min(.05, (now - last) / 1000 || 0); last = now;
  tScene += dt; uT.value = tScene;
  ema += (dt - ema) * .05; prT += dt; frames++;
  if (frames > 30 && dt > .004) baseDt = Math.min(Math.max(baseDt === .0167 && frames < 90 ? dt : Math.min(baseDt, dt), .007), .034);   // ekranın real kadr intervalı (60/120/30 FPS)
  if (quality === 'auto' && prT > 1) {
    prT = 0;
    if (ema > Math.max(.024, baseDt * 1.8) && pr > .85) setPR(Math.max(.85, pr * .9));
    else if (ema < baseDt * 1.3 && pr < prMax) setPR(Math.min(prMax, pr * 1.08));
  }

  // hover (raycast)
  hovered = null; hoveredShip = false;
  if (!focusName && ndc.x < 2 && !fly) {
    ray.setFromCamera(ndc, camera);
    const hits = ray.intersectObjects(pickables(), false);
    if (hits.length) { const u = hits[0].object.userData; if (u.ship) hoveredShip = true; else hovered = u.planet; }
  }
  const anyHover = hovered || hoveredShip || (ship && ship.hl) || [...planets.values()].some(p => p.hl);
  canvas.style.cursor = hovered || hoveredShip ? 'pointer' : 'grab';
  speed += ((anyHover && !focusName ? 0 : 1) - speed) * Math.min(1, dt * 6);
  const k = REDUCE ? 0 : dt * speed * (focusName ? .35 : 1);

  G += k * .022;
  for (const p of planets.values()) {
    p.r += (p.rT - p.r) * Math.min(1, dt * 2); p.R += (p.RT - p.R) * Math.min(1, dt * 2);
    p.off += Math.atan2(Math.sin(p.offT - p.off), Math.cos(p.offT - p.off)) * Math.min(1, dt * 1.5);
    p.a = G + p.off;
    if (p.kick.lengthSq() > 1e-6 || p.kickV.lengthSq() > 1e-6) { p.kickV.addScaledVector(p.kick, -22 * dt).multiplyScalar(Math.exp(-3 * dt)); p.kick.addScaledVector(p.kickV, dt); }
    p.holder.position.set(Math.cos(p.a) * p.r, 0, Math.sin(p.a) * p.r).add(p.kick);
    p.orbitLine.scale.setScalar(p.r);
    p.orbitLine.visible = !focusName;
    p.body.scale.setScalar(p.R);
    if (!REDUCE) { p.mesh.rotation.y += dt * p.spin * (1 - (p.danceK || 0)); if (p.clouds) p.clouds.rotation.y += dt * p.spin * 1.35; }

    const tc = new THREE.Color(STC[p.st] || STC.offline);
    p.col.lerp(tc, Math.min(1, dt * 4));
    const live = p.st === 'working' || p.st === 'waiting';
    const off = p.st === 'offline' || p.st === 'ended';
    p.dim += ((off ? .35 : 1) - p.dim) * Math.min(1, dt * 3); p.uni.uDim.value = p.dim;
    p.lights += ((live ? 1 : off ? 0 : .5) - p.lights) * Math.min(1, dt * 3); p.uni.uLights.value = p.lights;
    p.hot += (((hovered === p || p.hl) ? 1 : 0) - p.hot) * Math.min(1, dt * 8); p.uni.uHot.value = p.hot;
    const pulse = p.st === 'waiting' ? .78 + .22 * Math.sin(tScene * 5) : p.st === 'working' ? .8 + .2 * Math.sin(tScene * 2.6) : 1;
    p.atmU.uCol.value.copy(p.col); p.atmU.uCol.value.lerp(new THREE.Color(p.atm.x, p.atm.y, p.atm.z), off ? .2 : .25);
    p.atmU.uAmt.value = (live ? 1.15 : off ? .25 : .65) * pulse;
    updateScars(p, dt);
    if (p.hurt > .01) { p.atmU.uCol.value.lerp(new THREE.Color(1, .28, .08), Math.min(1, p.hurt * .7)); p.atmU.uAmt.value += p.hurt * (.5 + .25 * Math.sin(tScene * 12)); }
    // işarə halqası
    const ph = (tScene * (p.st === 'waiting' ? .9 : .5)) % 1;
    p.beacon.material.color.copy(p.col);
    p.beacon.material.opacity = live ? (1 - ph) * .55 : 0;
    p.beacon.scale.setScalar(2.4 + ph * 3.2);
    p.glow.material.color.copy(p.col); p.glow.material.opacity = (live ? .28 : off ? 0 : .1) * (.8 + .2 * pulse);
    if (p.ring) { p.holder.getWorldPosition(wp); p.ring.material.uniforms.uPC.value.copy(wp); p.ring.material.uniforms.uR.value = p.R; }
    if (p.ring) { p.ring.material.uniforms.uR.value = p.R; }

    if (!REDUCE) {
      if (p.st === 'working') { p.nextBurst -= dt; if (p.nextBurst <= 0) { spawnBurst(p, Math.random() < .25); p.nextBurst = rand(.5, 1.6); } }
      updateBursts(p);
      updateImpacts(p);
      updatePerf(p, dt);
    }

    for (const m of p.moons.values()) {
      m.r += (m.rT - m.r) * Math.min(1, dt * 2);
      const w = m.st === 'working' ? .9 : m.st === 'waiting' ? .55 : m.st === 'idle' ? .3 : .12;
      if (!REDUCE) m.a += dt * w * (focusName ? 1 : speed);
      m.holder.position.set(Math.cos(m.a) * m.r, 0, Math.sin(m.a) * m.r);
      m.ml.scale.setScalar(m.r); m.ml.material.opacity = focusName === p.name ? .22 : 0;
      const dead = m.st === 'offline' || m.st === 'ended';
      m.glow.material.opacity = dead ? .18 : .7; m.glow.scale.setScalar((dead ? .8 : 1.1) * (focusName === p.name ? 1 : 1.35));
    }
  }

  updateShip(dt);
  if (!REDUCE) updateRockets(dt);

  // sürüş / izləmə
  camera.position.sub(shakeOff);
  if (cine) { controls.enabled = false; cineStep(dt); }
  else if (fly) {
    const u = Math.min(1, (performance.now() - fly.t0) / fly.dur), e = ease(u);
    if (fly.follow) {
      fly.follow.holder.getWorldPosition(tmp);
      controls.target.lerpVectors(fly.ft, tmp, e);
      camera.position.lerpVectors(fly.fp, tmp2.copy(tmp).add(fly.to.off), e);
    } else {
      controls.target.lerpVectors(fly.ft, fly.to.target, e);
      camera.position.lerpVectors(fly.fp, fly.to.pos, e);
    }
    controls.enabled = false;
    if (u >= 1) { fly = null; controls.enabled = true; }
  } else if (!controls.enabled) { controls.enabled = true; } else if (focusName && focusObj()) {
    const p = focusObj();
    p.holder.getWorldPosition(tmp);
    tmp2.copy(tmp).sub(controls.target);
    camera.position.add(tmp2); controls.target.copy(tmp);
  }
  if (fly || cine) zT = null;
  if (zT !== null && !fly) {
    tmp.copy(camera.position).sub(controls.target);
    const cur = tmp.length(), nd = cur + (zT - cur) * Math.min(1, dt * 9);
    camera.position.copy(controls.target).add(tmp.setLength(nd));
    if (Math.abs(zT - nd) < .02) zT = null;
  }
  const insT = focusName && W > 760 ? Math.min(430, W * .4) / 2 + 7 : 0;
  inset += (insT - inset) * Math.min(1, dt * (REDUCE ? 60 : 4));
  if (Math.abs(inset) > .4) camera.setViewOffset(W, H, inset, 0, W, H); else if (camera.view && camera.view.enabled) camera.clearViewOffset();
  updateSound(dt);
  controls.autoRotate = !REDUCE && !focusName && !anyHover && !fly && !downAt && !cine;
  controls.update();
  shakeOff.set(0, 0, 0);
  if (shake > .004 && !REDUCE) {
    const a = shake * camera.position.distanceTo(controls.target) * .014;
    shakeOff.set(rand(-1, 1) * a, rand(-1, 1) * a, rand(-1, 1) * a); camera.position.add(shakeOff); shake *= Math.exp(-dt * 3.4);
  } else shake = 0;

  stars.position.copy(camera.position); sky.position.copy(camera.position);
  sky.rotation.y += dt * .0025;
  const sp = 1 + Math.sin(tScene * 1.3) * .03;
  sunGlow1.scale.setScalar(30 * sp); sunGlow2.scale.setScalar(78 + Math.sin(tScene * .7) * 3);
  sunMesh.rotation.y += dt * .02;
  sunLbl.style.opacity = focusName ? 0 : 1;
  applyLabel(sunLbl, labelPos(sunWP, 5.2, !focusName));
  const items = [];
  for (const p of planets.values()) {
    p.holder.getWorldPosition(wp);
    items.push({ p, q: labelPos(wp, p.R, !focusName) });
    p.btn.classList.toggle('hov', hovered === p || p.hl);
  }
  if (ship) { ship.holder.getWorldPosition(shipWP); items.push({ p: ship, q: labelPos(shipWP, ship.R, !focusName), ship: true }); ship.btn.classList.toggle('hov', hoveredShip || ship.hl); }
  items.sort((a, b) => (a.q ? a.q.y : 1e9 - 0) - (b.q ? b.q.y : 1e9));
  for (let i = 0; i < items.length; i++) for (let j = i + 1; j < items.length; j++) {
    const A = items[i].q, B = items[j].q;
    if (A && B && Math.abs(A.x - B.x) < 110 && Math.abs(A.y - B.y) < 38) B.y = A.y + 38;
  }
  for (const it of items) applyLabel(it.p.btn, it.q);
  renderer.render(scene, camera);
}

api.mount = function (el) {
  if (api.mounted) return true;
  try {
    box = el;
    pr = prMax = Math.min(devicePixelRatio || 1, 2);
    renderer = new THREE.WebGLRenderer({ antialias: true, powerPreference: 'high-performance' });
  } catch (e) { return false; }
  if (!renderer.getContext()) return false;
  canvas = renderer.domElement; canvas.className = 'glcanvas';
  box.prepend(canvas);
  lblBox = document.createElement('div'); lblBox.className = 'glbls'; box.insertBefore(lblBox, canvas.nextSibling);
  sunLbl = document.createElement('div'); sunLbl.className = 'glabel sunl'; sunLbl.textContent = 'Komanda'; lblBox.append(sunLbl);
  kfl = document.createElement('div'); kfl.className = 'kflash'; box.append(kfl);

  scene = new THREE.Scene();
  camera = new THREE.PerspectiveCamera(45, 1, .1, 3000);
  glowTex = radialTex([[0, 'rgba(255,255,255,1)'], [.18, 'rgba(255,255,255,.55)'], [.5, 'rgba(255,255,255,.12)'], [1, 'rgba(255,255,255,0)']]);
  beaconTex = ringTex();

  // səma + ulduzlar
  const bakeScene = new THREE.Scene();
  bakeScene.add(new THREE.Mesh(new THREE.SphereGeometry(10, 32, 16), new THREE.ShaderMaterial({ vertexShader: SKY_VS, fragmentShader: SKY_FS, uniforms: { uTime: { value: 12 } }, side: THREE.BackSide })));
  const skyRT = new THREE.WebGLCubeRenderTarget(768);
  new THREE.CubeCamera(.1, 100, skyRT).update(renderer, bakeScene);
  sky = new THREE.Mesh(new THREE.SphereGeometry(1000, 32, 16), new THREE.ShaderMaterial({
    vertexShader: SKY_VS, uniforms: { tCube: { value: skyRT.texture } }, side: THREE.BackSide, depthWrite: false,
    fragmentShader: 'uniform samplerCube tCube; varying vec3 vDir; void main(){ gl_FragColor=vec4(textureCube(tCube,normalize(vDir)).rgb,1.); }' }));
  sky.renderOrder = -2; scene.add(sky);
  const N = 3000, pos = new Float32Array(N * 3), size = new Float32Array(N), ph = new Float32Array(N), col = new Float32Array(N * 3), r = rnd(7);
  for (let i = 0; i < N; i++) {
    const u = r() * 2 - 1, a = r() * 6.2832, s = Math.sqrt(1 - u * u), Rr = 700;
    pos.set([Rr * s * Math.cos(a), Rr * u, Rr * s * Math.sin(a)], i * 3);
    size[i] = .9 + Math.pow(r(), 7) * 4.6; ph[i] = r();
    const q = r(), c = q < .14 ? [1, .78, .58] : q < .3 ? [.66, .78, 1] : [.92, .94, 1];
    col.set(c, i * 3);
  }
  const sg = new THREE.BufferGeometry();
  sg.setAttribute('position', new THREE.BufferAttribute(pos, 3)); sg.setAttribute('size', new THREE.BufferAttribute(size, 1));
  sg.setAttribute('phase', new THREE.BufferAttribute(ph, 1)); sg.setAttribute('color', new THREE.BufferAttribute(col, 3));
  stars = new THREE.Points(sg, new THREE.ShaderMaterial({ vertexShader: STAR_VS, fragmentShader: STAR_FS, uniforms: { uTime: uT, uPR: { value: 1 } }, transparent: true, depthWrite: false, blending: THREE.AdditiveBlending }));
  stars.renderOrder = -1; stars.frustumCulled = false; scene.add(stars);

  // günəş
  sunMesh = new THREE.Mesh(new THREE.SphereGeometry(5.2, 96, 64), new THREE.ShaderMaterial({ vertexShader: SUN_VS, fragmentShader: SUN_FS, uniforms: { uTime: uT } }));
  scene.add(sunMesh);
  sunGlow1 = new THREE.Sprite(new THREE.SpriteMaterial({ map: glowTex, color: 0xffa040, transparent: true, opacity: .85, depthWrite: false, blending: THREE.AdditiveBlending }));
  sunGlow2 = new THREE.Sprite(new THREE.SpriteMaterial({ map: glowTex, color: 0xff7a30, transparent: true, opacity: .28, depthWrite: false, blending: THREE.AdditiveBlending }));
  scene.add(sunGlow1, sunGlow2);
  buildShip();

  controls = new OrbitControls(camera, canvas);
  controls.enableDamping = true; controls.dampingFactor = .06; controls.enablePan = false;
  controls.enableZoom = true; controls.rotateSpeed = .7; controls.zoomSpeed = 1.1; controls.autoRotateSpeed = .45; controls.maxPolarAngle = Math.PI * .92; controls.minPolarAngle = .08;
  controls.addEventListener('start', () => { userMoved = true; });
  overview(true);

  canvas.addEventListener('pointermove', e => {
    const r = canvas.getBoundingClientRect(); ndc.set(((e.clientX - r.left) / r.width) * 2 - 1, -((e.clientY - r.top) / r.height) * 2 + 1);
    if (downAt && Math.hypot(e.clientX - downAt.x, e.clientY - downAt.y) > 5) moved = true;
  });
  canvas.addEventListener('pointerleave', () => { ndc.set(9, 9); });
  canvas.addEventListener('pointerdown', e => { downAt = { x: e.clientX, y: e.clientY }; moved = false; if (cine) endCine(true); });
  addEventListener('pointerup', e => {
    if (downAt && !moved && !focusName && e.target === canvas) {
      const r = canvas.getBoundingClientRect(); ndc.set(((e.clientX - r.left) / r.width) * 2 - 1, -((e.clientY - r.top) / r.height) * 2 + 1);
      ray.setFromCamera(ndc, camera);
      const hits = ray.intersectObjects(pickables(), false);
      if (hits.length) { const u = hits[0].object.userData; if (u.ship) api.onShip && api.onShip(); else if (api.onPick) api.onPick(u.planet.name); }
    }
    downAt = null;
  });
  box.addEventListener('wheel', e => {
    if (e.target.closest && e.target.closest('.dock')) return;
    e.preventDefault(); e.stopPropagation();
    const dy = e.deltaMode === 1 ? e.deltaY * 16 : e.deltaMode === 2 ? e.deltaY * 100 : e.deltaY;
    api.zoom(Math.exp(dy * (e.ctrlKey ? .012 : .0019)));
  }, { passive: false, capture: true });
  new ResizeObserver(resize).observe(box);
  resize();
  api.mounted = true;
  requestAnimationFrame(frame);
  return true;
};

window.Kainat = api;
window.dispatchEvent(new Event('kainat-ready'));
