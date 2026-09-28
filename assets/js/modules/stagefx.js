/* Big-screen light effects, drawn on a canvas over the scenes: glitter, stars, confetti,
   spotlights, light orbs and fireworks, plus a one-shot confetti burst (cannons from both sides).
   Each effect runs in its own layer, so switching effects cross-fades: the old one stops adding
   particles and fades out while the new one fades in. Sizes and speeds use S = 1% of the frame
   width, so they look the same on any screen. */
(function () {
  'use strict';

  const GOLD = ['#FFD86B', '#F5C542', '#FFF3C4', '#FFFFFF', '#E8B64C'];
  const CONFETTI = ['#C8A02C', '#FFD86B', '#FFFFFF', '#0B6B32', '#1D4ED8', '#B91C1C', '#7C3AED', '#F472B6'];
  const FIREWORK = ['#FFD86B', '#FFFFFF', '#F472B6', '#60A5FA', '#34D399', '#F87171', '#C084FC'];
  const FADE_IN = 1.0; // seconds for a new effect to reach full strength
  const FADE_OUT = 1.8; // seconds for a switched-off effect to disappear
  const TAU = Math.PI * 2;
  const rnd = (a, b) => a + Math.random() * (b - a);
  const pick = (list) => list[(Math.random() * list.length) | 0];

  const StageFx = (window.StageFx = {});

  StageFx.mount = (canvas) => {
    const ctx = canvas.getContext('2d');
    let W = 0, H = 0, S = 1, density = 1;
    let layers = []; // { name, parts, born, alpha, on, launch, beams }
    let bursts = []; // confetti burst pieces (independent of the effects)
    let t = 0, last = 0, running = false;
    let fade = 1; // opacity of the layer being drawn

    function resize() {
      const dpr = Math.min(1.5, window.devicePixelRatio || 1);
      W = canvas.clientWidth;
      H = canvas.clientHeight;
      canvas.width = Math.max(1, Math.round(W * dpr));
      canvas.height = Math.max(1, Math.round(H * dpr));
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      S = Math.min(W, (H * 16) / 9) / 100;
      // fewer particles on small screens (the control panel's monitor)
      density = Math.max(0.25, Math.min(1.4, (W * H) / (1920 * 1080)));
    }
    new ResizeObserver(resize).observe(canvas);
    resize();

    /* ---------------------------------------------------------------- shapes */

    /** Opacity of what is drawn next, dimmed by the fade of its layer. */
    const alpha = (a) => (ctx.globalAlpha = Math.max(0, Math.min(1, a * fade)));

    function diamond(x, y, size, rot) {
      ctx.save();
      ctx.translate(x, y);
      ctx.rotate(rot + Math.PI / 4);
      ctx.fillRect(-size / 2, -size / 2, size, size);
      ctx.restore();
    }

    function star4(x, y, s) {
      const k = s * 0.18;
      ctx.beginPath();
      ctx.moveTo(x, y - s);
      ctx.lineTo(x + k, y - k);
      ctx.lineTo(x + s, y);
      ctx.lineTo(x + k, y + k);
      ctx.lineTo(x, y + s);
      ctx.lineTo(x - k, y + k);
      ctx.lineTo(x - s, y);
      ctx.lineTo(x - k, y - k);
      ctx.closePath();
      ctx.fill();
    }

    function glow(x, y, r, rgb, a) {
      const g = ctx.createRadialGradient(x, y, 0, x, y, r);
      g.addColorStop(0, `rgba(${rgb},${a})`);
      g.addColorStop(1, `rgba(${rgb},0)`);
      alpha(1);
      ctx.fillStyle = g;
      ctx.fillRect(x - r, y - r, r * 2, r * 2);
    }

    const confettiPiece = (x, y, vx, vy) => ({
      x, y, vx, vy, w: rnd(0.45, 0.8) * S, h: rnd(0.22, 0.4) * S, rot: rnd(0, TAU), vr: rnd(-5, 5),
      flip: rnd(0, TAU), vf: rnd(4, 10), c: pick(CONFETTI), sw: rnd(1, 3), ph: rnd(0, TAU),
    });

    function drawConfetti(p) {
      ctx.save();
      ctx.translate(p.x, p.y);
      ctx.rotate(p.rot);
      const flip = Math.cos(p.flip);
      ctx.scale(1, flip);
      alpha(flip < 0 ? 0.75 : 1);
      ctx.fillStyle = p.c;
      ctx.fillRect(-p.w / 2, -p.h / 2, p.w, p.h);
      ctx.restore();
    }

    /* ---------------------------------------------------------------- effects
       spawn(init)       a new particle (init: spread over the whole screen at the start)
       tick(dt, L)       once per frame; L.on is false while the layer fades out
       update(p, dt, L)  moves a particle; false removes it
       draw(p)           draws a particle */

    const FX = {
      glitter: {
        count: 180,
        light: true,
        spawn: (init) => ({
          x: rnd(0, W), y: init ? rnd(0, H) : rnd(-20, -4), vy: rnd(4, 10) * S, sw: rnd(0.5, 2), ph: rnd(0, TAU),
          size: rnd(0.12, 0.34) * S, rot: rnd(0, TAU), vr: rnd(-3, 3), c: pick(GOLD), tw: rnd(3, 8),
        }),
        update: (p, dt) => {
          p.y += p.vy * dt;
          p.x += Math.sin(t * p.sw + p.ph) * S * 0.3 * dt;
          p.rot += p.vr * dt;
          return p.y < H + 20;
        },
        draw: (p) => {
          const a = 0.3 + 0.7 * Math.abs(Math.sin(t * p.tw + p.ph));
          alpha(a);
          ctx.fillStyle = p.c;
          diamond(p.x, p.y, p.size, p.rot);
          if (a > 0.93) {
            // a glint
            alpha((a - 0.93) * 10);
            ctx.fillRect(p.x - p.size * 2, p.y - 0.5, p.size * 4, 1);
            ctx.fillRect(p.x - 0.5, p.y - p.size * 2, 1, p.size * 4);
          }
        },
      },

      stars: {
        count: 60,
        light: true,
        spawn: (init) => {
          const dur = rnd(1.2, 3.5);
          return { x: rnd(0, W), y: rnd(0, H), life: init ? rnd(0, dur) : 0, dur, size: rnd(0.4, 1.3) * S, c: pick(['#FFFFFF', '#FFF3C4', '#FFD86B']) };
        },
        tick: (dt, L) => {
          if (L.on && Math.random() < dt * 0.35) {
            // a shooting star now and then
            L.parts.push({ shoot: true, x: rnd(W * 0.3, W * 1.05), y: rnd(-H * 0.05, H * 0.35), vx: -rnd(60, 90) * S, vy: rnd(18, 30) * S, life: 0, dur: rnd(0.7, 1.1) });
          }
        },
        update: (p, dt) => {
          p.life += dt;
          if (p.shoot) {
            p.x += p.vx * dt;
            p.y += p.vy * dt;
          }
          return p.life < p.dur;
        },
        draw: (p) => {
          const k = Math.sin((Math.PI * p.life) / p.dur);
          if (p.shoot) {
            const tail = ctx.createLinearGradient(p.x, p.y, p.x - p.vx * 0.12, p.y - p.vy * 0.12);
            tail.addColorStop(0, `rgba(255,255,255,${0.9 * k})`);
            tail.addColorStop(1, 'rgba(255,255,255,0)');
            alpha(1);
            ctx.strokeStyle = tail;
            ctx.lineWidth = 0.18 * S;
            ctx.beginPath();
            ctx.moveTo(p.x, p.y);
            ctx.lineTo(p.x - p.vx * 0.12, p.y - p.vy * 0.12);
            ctx.stroke();
            return;
          }
          glow(p.x, p.y, p.size * 2.2, '255,236,170', 0.35 * k);
          alpha(k);
          ctx.fillStyle = p.c;
          star4(p.x, p.y, p.size * k);
        },
      },

      confetti: {
        count: 130,
        light: false,
        spawn: (init) => confettiPiece(rnd(0, W), init ? rnd(-H, H) : rnd(-H * 0.2, -10), rnd(-2, 2) * S, rnd(8, 16) * S),
        update: (p, dt) => {
          p.y += p.vy * dt;
          p.x += p.vx * dt + Math.sin(t * p.sw + p.ph) * S * 0.8 * dt;
          p.rot += p.vr * dt;
          p.flip += p.vf * dt;
          return p.y < H + 20;
        },
        draw: drawConfetti,
      },

      spotlights: {
        count: 0,
        light: true,
        tick: (dt, L) => {
          L.beams.forEach((b) => {
            const angle = Math.sin(t * b.speed + b.phase) * 0.5;
            const ox = b.x * W, oy = -H * 0.06, len = H * 1.3, half = H * 0.24;
            const dx = Math.sin(angle), dy = Math.cos(angle);
            const ex = ox + dx * len, ey = oy + dy * len;
            const g = ctx.createLinearGradient(ox, oy, ex, ey);
            g.addColorStop(0, `rgba(${b.rgb},0.5)`);
            g.addColorStop(0.55, `rgba(${b.rgb},0.14)`);
            g.addColorStop(1, `rgba(${b.rgb},0)`);
            alpha(1);
            ctx.fillStyle = g;
            ctx.beginPath();
            ctx.moveTo(ox - dy * S * 0.6, oy + dx * S * 0.6);
            ctx.lineTo(ex - dy * half, ey + dx * half);
            ctx.lineTo(ex + dy * half, ey - dx * half);
            ctx.lineTo(ox + dy * S * 0.6, oy - dx * S * 0.6);
            ctx.closePath();
            ctx.fill();
            glow(ox, 0, S * 4, b.rgb, 0.55); // the lamp
          });
        },
      },

      orbs: {
        count: 26,
        light: true,
        spawn: (init) => {
          const r = rnd(2, 6.5) * S;
          return {
            x: rnd(0, W), y: init ? rnd(0, H) : H + r + rnd(0, 60), r, vy: rnd(1.5, 4) * S, vx: rnd(-1, 1) * S,
            a: rnd(0.12, 0.32), ph: rnd(0, TAU), rgb: pick(['255,216,107', '255,255,255', '120,200,140', '255,190,120', '160,190,255']),
          };
        },
        update: (p, dt) => {
          p.y -= p.vy * dt;
          p.x += p.vx * dt + Math.sin(t * 0.5 + p.ph) * S * 0.3 * dt;
          return p.y > -p.r * 2;
        },
        draw: (p) => glow(p.x, p.y, p.r, p.rgb, p.a * (0.7 + 0.3 * Math.sin(t * 1.3 + p.ph))),
      },

      fireworks: {
        count: 0,
        light: true,
        tick: (dt, L) => {
          if (!L.on) return; // no new rockets while fading out; the sparks in the air finish
          L.launch -= dt;
          if (L.launch <= 0) {
            L.launch = rnd(0.35, 1.0) / Math.max(0.6, density);
            const g = 40 * S;
            const top = rnd(0.12, 0.45) * H;
            L.parts.push({ rocket: true, x: rnd(W * 0.12, W * 0.88), y: H + 5, vx: rnd(-3, 3) * S, vy: -Math.sqrt(2 * g * (H + 5 - top)), g });
          }
        },
        update: (p, dt, L) => {
          if (p.rocket) {
            p.vy += p.g * dt;
            p.x += p.vx * dt;
            p.y += p.vy * dt;
            if (p.vy >= 0) {
              // explode into sparks of one colour, with a few white ones
              const c = pick(FIREWORK);
              const n = Math.round(70 * Math.max(0.5, density));
              const speed = rnd(18, 28) * S;
              for (let i = 0; i < n; i++) {
                const a = (i / n) * TAU + rnd(-0.05, 0.05);
                const v = speed * rnd(0.55, 1);
                const dur = rnd(1, 1.8);
                L.born.push({ spark: true, x: p.x, y: p.y, vx: Math.cos(a) * v, vy: Math.sin(a) * v, g: p.g * 0.35, life: dur, dur, c: Math.random() < 0.15 ? '#FFFFFF' : c });
              }
              return false;
            }
            return true;
          }
          const drag = Math.pow(0.985, dt * 60);
          p.vx *= drag;
          p.vy = p.vy * drag + p.g * dt;
          p.x += p.vx * dt;
          p.y += p.vy * dt;
          p.life -= dt;
          return p.life > 0;
        },
        draw: (p) => {
          if (p.rocket) {
            glow(p.x, p.y, S * 0.9, '255,220,140', 0.9);
            return;
          }
          alpha(p.life / p.dur);
          ctx.strokeStyle = p.c;
          ctx.lineWidth = 0.22 * S;
          ctx.beginPath();
          ctx.moveTo(p.x, p.y);
          ctx.lineTo(p.x - p.vx * 0.05, p.y - p.vy * 0.05);
          ctx.stroke();
        },
      },
    };

    function newLayer(name) {
      const fx = FX[name];
      const L = { name, parts: [], born: [], alpha: 0, on: true, launch: 0, beams: [] };
      if (name === 'spotlights') {
        L.beams = [
          { x: 0.12, rgb: '255,236,190', speed: 0.55, phase: 0 },
          { x: 0.38, rgb: '255,210,120', speed: 0.7, phase: 2.1 },
          { x: 0.62, rgb: '190,215,255', speed: 0.62, phase: 4.2 },
          { x: 0.88, rgb: '255,190,230', speed: 0.5, phase: 1.3 },
        ];
      }
      if (fx.spawn) {
        const n = Math.round(fx.count * density);
        for (let i = 0; i < n; i++) L.parts.push(fx.spawn(true));
      }
      return L;
    }

    /* ---------------------------------------------------------------- loop */

    function drawLayer(L, dt) {
      const fx = FX[L.name];
      // fade in while on, fade out once switched off
      L.alpha = L.on ? Math.min(1, L.alpha + dt / FADE_IN) : Math.max(0, L.alpha - dt / FADE_OUT);
      fade = L.alpha;
      ctx.globalCompositeOperation = fx.light ? 'lighter' : 'source-over';
      if (fx.tick) fx.tick(dt, L);
      if (fx.spawn && L.on) {
        // keep the screen filled: replace the particles that left it (not while fading out)
        const target = Math.round(fx.count * density);
        let alive = 0;
        for (const p of L.parts) if (!p.shoot) alive++;
        for (let i = alive; i < target; i++) L.parts.push(fx.spawn(false));
      }
      if (fx.update) {
        L.parts = L.parts.filter((p) => fx.update(p, dt, L));
        if (L.born.length) L.parts = L.parts.concat(L.born.splice(0));
        L.parts.forEach(fx.draw);
      }
    }

    function frame(ts) {
      const dt = Math.min(0.05, last ? (ts - last) / 1000 : 0.016);
      last = ts;
      t += dt;
      ctx.clearRect(0, 0, W, H);

      layers.forEach((L) => drawLayer(L, dt));
      layers = layers.filter((L) => L.on || L.alpha > 0);

      if (bursts.length) {
        fade = 1;
        ctx.globalCompositeOperation = 'source-over';
        bursts = bursts.filter((p) => {
          const drag = Math.pow(0.975, dt * 60);
          p.vx *= drag;
          p.vy = Math.min(p.vy * drag + 45 * S * dt, 11 * S); // pieces flutter once they fall
          p.x += p.vx * dt + (p.vy > 0 ? Math.sin(t * p.sw + p.ph) * S * 0.8 * dt : 0);
          p.y += p.vy * dt;
          p.rot += p.vr * dt;
          p.flip += p.vf * dt;
          return p.y < H + 30;
        });
        bursts.forEach(drawConfetti);
      }
      ctx.globalAlpha = 1;
      ctx.globalCompositeOperation = 'source-over';

      if (!layers.length && !bursts.length) {
        running = false;
        ctx.clearRect(0, 0, W, H);
        return;
      }
      requestAnimationFrame(frame);
    }

    function start() {
      if (running) return;
      running = true;
      last = 0;
      requestAnimationFrame(frame);
    }

    return {
      /** Switches the continuous effect ('none' fades it out). */
      set(name) {
        name = FX[name] ? name : 'none';
        const current = layers.find((L) => L.on);
        if ((current ? current.name : 'none') === name) return;
        layers.forEach((L) => (L.on = false));
        if (name !== 'none') layers.push(newLayer(name));
        layers = layers.slice(-3); // quick switching: at most two effects still fading out
        start();
      },

      /** Confetti cannons from both bottom corners. */
      burst() {
        const n = Math.round(130 * Math.max(0.5, density));
        [-1, 1].forEach((side) => {
          for (let i = 0; i < n; i++) {
            bursts.push(confettiPiece(side < 0 ? rnd(0, W * 0.08) : rnd(W * 0.92, W), H + 10, -side * rnd(8, 40) * S, -rnd(60, 98) * S));
          }
        });
        start();
      },
    };
  };
})();
