(() => {
  document.documentElement.classList.add("js");
  const S = window.SITE || {};
  const reduzir = matchMedia("(prefers-reduced-motion: reduce)").matches;
  const celular = matchMedia("(max-width: 760px)").matches;


  /* Sequência: carregamento sob demanda e memória limitada. */
  const cine = document.querySelector('.cine');
  const canvas = cine?.querySelector('.cine-canvas');
  if (cine && canvas && S.frames?.total > 1) {
    cine.style.setProperty('--altura', reduzir ? '100svh' : '420vh');
    const ctx = canvas.getContext('2d');
    const total = S.frames.total;
    const cache = new Map();
    const pending = new Set();
    let current = 0, target = 0, raf = 0, active = 0, drawn = -1;
    let mobile = matchMedia('(max-width: 760px)').matches;
    const queue = [];
    const moments = [...cine.querySelectorAll('.momento')];
    const reveal = cine.querySelector('.cine-revelacao');
    const rim = cine.querySelector('.gota-borda');
    const about = document.querySelector('#sobre');
    let focusAbout = false;
    if (!reduzir && reveal && about) {
      // Move the real section into the reveal, rather than showing it twice.
      reveal.append(about);
      about.classList.add('portal-conteudo');
      about.setAttribute('tabindex', '-1');
      about.querySelectorAll('.revelar').forEach(el => el.classList.add('visto'));
      const openAbout = (smooth = true) => {
        focusAbout = true;
        const origin = scrollY + cine.getBoundingClientRect().top;
        const progress = Math.min(.98, (S.cine?.portalEnd ?? .82) + .1);
        scrollTo({top: origin + (cine.offsetHeight-innerHeight)*progress, behavior: smooth ? 'smooth' : 'instant'});
        update();
      };
      document.querySelectorAll('a[href="#sobre"]').forEach(link => link.addEventListener('click', ev => {
        ev.preventDefault();
        history.replaceState(null, '', '#sobre');
        openAbout();
      }));
      if (location.hash === '#sobre') requestAnimationFrame(() => openAbout(false));
    }
    let centerIndex = 0;
    let dropPoint = {x: innerWidth / 2, y: innerHeight * .3};
    function paint(index) {
      let best = index;
      if (!cache.has(best)) {
        let distance = Infinity;
        for (const k of cache.keys()) if (Math.abs(k-index) < distance) {best=k;distance=Math.abs(k-index);}
      }
      const img = cache.get(best);
      if (!img || drawn === best) return;
      // Fit the native frame once; never enlarge a small droplet into a blurred screen.
      const scale = Math.max(canvas.width/img.naturalWidth,canvas.height/img.naturalHeight);
      const w=img.naturalWidth*scale,h=img.naturalHeight*scale;
      const focal = mobile ? .58 - index/(total-1)*.234 : .5;
      const x = Math.min(0, Math.max(canvas.width-w, canvas.width/2-w*focal));
      const y = (canvas.height-h)/2;
      ctx.drawImage(img,x,y,w,h);
      const point = mobile ? S.cine?.drop?.mobile : S.cine?.drop?.desktop;
      const dpr = canvas.width / canvas.clientWidth || 1;
      const cr = canvas.getBoundingClientRect(), stage = cine.querySelector('.cine-palco').getBoundingClientRect();
      dropPoint = {
        x: (x + w*(point?.x ?? .5))/dpr + cr.left-stage.left,
        y: (y + h*(point?.y ?? .3))/dpr + cr.top-stage.top,
      };
      drawn=best;
    }
    function pump() {
      while (active<4 && queue.length) {
        const index=queue.shift();
        if (cache.has(index)) {pending.delete(index);continue;}
        active++;
        const img=new Image();img.decoding='async';
        img.onload=()=>{
          cache.set(index,img);pending.delete(index);active--;
          if(cache.size>24){const candidates=[...cache.keys()].sort((a,b)=>Math.abs(b-centerIndex)-Math.abs(a-centerIndex));while(cache.size>24)cache.delete(candidates.shift());}
          drawn=-1;paint(centerIndex);pump();
        };
        img.onerror=()=>{pending.delete(index);active--;pump();};
        img.src=(mobile?S.frames.mobile:S.frames.desktop).replace('{n}',String(index+1).padStart(4,'0'));
      }
    }
    function preload(index) {
      centerIndex = index;
      for(const d of (reduzir ? [0] : [0,1,-1,2,-2,3,-3,5,-5,8,-8])){
        const k=index+d;
        if(k>=0&&k<total&&!cache.has(k)&&!pending.has(k)){pending.add(k);queue.push(k);}
      }
      // On a fast scroll, prioritize the requested frame over stale neighbors.
      for(let q=queue.length-1;q>=0;q--){if(Math.abs(queue[q]-index)>16){pending.delete(queue[q]);queue.splice(q,1);}}
      queue.sort((a,b)=>Math.abs(a-index)-Math.abs(b-index));pump();
    }
    function tick(){
      raf=0;
      current += (target-current)*.22;
      if(Math.abs(target-current)<.05)current=target;
      const p=current/(total-1);
      const pressEnd = S.cine?.pressEnd ?? .72;
      const frameIndex = Math.round(Math.min(1,p/pressEnd)*(total-1));
      preload(frameIndex);paint(frameIndex);
      for(const m of moments){const visible=p>=Number(m.dataset.de)&&p<=Number(m.dataset.ate);m.classList.toggle('ativo',visible);m.inert=!visible;}
      cine.classList.toggle('andou',p>.02);
      if(reveal){
        // The next section is rendered at its native size INSIDE the droplet.
        // Only the vector aperture grows, so its content stays sharp.
        const portalEnd = S.cine?.portalEnd ?? .82;
        const t = Math.max(0,Math.min(1,(p-pressEnd)/(portalEnd-pressEnd)));
        const start = Math.max(8,Math.min(innerWidth,innerHeight)*.018);
        const finish = Math.hypot(innerWidth,innerHeight)*1.1;
        const radius = start * (Math.pow((finish+start)/start,t)-1);
        const center = `${dropPoint.x.toFixed(1)}px ${dropPoint.y.toFixed(1)}px`;
        reveal.style.clipPath=`circle(${radius.toFixed(1)}px at ${center})`;
        const readable = !reduzir && t >= 1;
        reveal.inert = !readable;
        reveal.setAttribute('aria-hidden', String(!readable));
        reveal.style.pointerEvents = readable ? 'auto' : 'none';
        if(readable && focusAbout){about?.focus({preventScroll:true});focusAbout=false;}
        if(rim){
          rim.style.left=`${dropPoint.x}px`;rim.style.top=`${dropPoint.y}px`;
          rim.style.width=rim.style.height=`${radius*2}px`;
          rim.style.opacity=String(Math.min(1,radius/start));
        }
      }
      if(current!==target)raf=requestAnimationFrame(tick);
    }
    function update(){
      const rect=cine.getBoundingClientRect();
      const progress=reduzir?0:Math.max(0,Math.min(1,-rect.top/(cine.offsetHeight-innerHeight||1)));
      target=progress*(total-1);
      if(!raf)raf=requestAnimationFrame(tick);
    }
    function resize(){
      const dpr=Math.min(devicePixelRatio||1,1.5,(mobile?720:1920)/canvas.clientWidth,1080/canvas.clientHeight);
      canvas.width=Math.round(canvas.clientWidth*dpr);canvas.height=Math.round(canvas.clientHeight*dpr);
      drawn=-1;update();
    }
    addEventListener('scroll',update,{passive:true});addEventListener('resize',resize);
    resize();
  }

  if (cine && (!S.frames || S.frames.total < 2)) {
    cine.style.setProperty('--altura', '100svh');
  }

  /* ── Parallax leve nas artes ──────────────────────────────────────────── */
  const artes = reduzir ? [] : [...document.querySelectorAll(".secao .arte")];
  if (artes.length) {
    const mover = () => {
      for (const a of artes) {
        const r = a.parentElement.getBoundingClientRect();
        if (r.bottom < 0 || r.top > innerHeight) continue;
        const c = (r.top + r.height / 2 - innerHeight / 2) / innerHeight;
        a.style.transform = `translate3d(0, ${(c * -60).toFixed(1)}px, 0) scale(1.08)`;
      }
    };
    addEventListener("scroll", () => requestAnimationFrame(mover), { passive: true });
    mover();
  }

  /* ── Topo, revelar e ano ──────────────────────────────────────────────── */
  const topo = document.querySelector(".topo");
  const whats = document.querySelector(".whats");
  let formNaTela = false; // o botão flutuante se recolhe para não cobrir o "Enviar"
  const aoRolar = () => {
    topo && topo.classList.toggle("rolou", scrollY > 40);
    whats && whats.classList.toggle("visivel", scrollY > innerHeight * 0.6 && !formNaTela);
  };
  const formEl = document.querySelector(".form");
  if (formEl) new IntersectionObserver(([e]) => { formNaTela = e.isIntersecting; aoRolar(); }).observe(formEl);
  addEventListener("scroll", aoRolar, { passive: true });
  aoRolar();

  const io = new IntersectionObserver((es) => es.forEach((e) => {
    if (e.isIntersecting) { e.target.classList.add("visto"); io.unobserve(e.target); }
  }), { threshold: 0.18 });
  document.querySelectorAll(".revelar").forEach((el) => io.observe(el));
  document.querySelectorAll("[data-ano]").forEach((el) => (el.textContent = new Date().getFullYear()));

  /* ── WhatsApp ─────────────────────────────────────────────────────────── */
  if (whats && S.whatsapp) {
    const num = String(S.whatsapp.numero || "").replace(/\D/g, "");
    whats.href = `https://wa.me/${num}?text=${encodeURIComponent(S.whatsapp.mensagem || "")}`;
  }

  /* Diferenciais em texto selecionável, com navegação por temas. */
  const benefits = document.querySelector('.secao--diferenciais');
  if (benefits) {
    const tabs = [...benefits.querySelectorAll('[role=tab]')];
    const panels = [...benefits.querySelectorAll('[role=tabpanel]')];
    const select = (index, focus = false) => {
      tabs.forEach((tab, i) => {
        tab.setAttribute('aria-selected', String(i === index));
        tab.tabIndex = i === index ? 0 : -1;
        panels[i].hidden = i !== index;
      });
      if(focus){tabs[index].focus({preventScroll:true});tabs[index].scrollIntoView?.({block:'nearest',inline:'nearest'});}
    };
    tabs.forEach((tab,index) => {
      tab.addEventListener('click', () => select(index));
      tab.addEventListener('keydown', ev => {
        let next;
        if(ev.key === 'ArrowRight') next = (index+1)%tabs.length;
        else if(ev.key === 'ArrowLeft') next = (index+tabs.length-1)%tabs.length;
        else if(ev.key === 'Home') next = 0;
        else if(ev.key === 'End') next = tabs.length-1;
        else return;
        ev.preventDefault();select(next,true);
      });
    });
    benefits.classList.add('com-abas');
    select(0);
  }

  // Keep the original messages intact in the mosaic.
  document.querySelectorAll('.mensagem-original').forEach(image => image.addEventListener('error', () => {
    image.hidden = true;
    image.closest('.depoimento').querySelector('.depo-falha').hidden = false;
  }, {once:true}));

  /* ── Formulário → e-mail ──────────────────────────────────────────────── */
  const form = document.querySelector(".form");
  if (form && S.contato) {
    const status = form.querySelector(".form-status");
    const botao = form.querySelector("button[type=submit]");
    const avisar = (txt, tipo) => { status.textContent = txt; status.className = `form-status campo--inteiro ${tipo || ""}`; };

    form.addEventListener("submit", async (ev) => {
      ev.preventDefault();
      if (form.elements._honey.value) return; // robô
      let valido = true;
      form.querySelectorAll("[required]").forEach((c) => {
        const ok = c.checkValidity() && c.value.trim().length > 1;
        c.setAttribute("aria-invalid", String(!ok));
        c.closest(".campo").classList.toggle("erro", !ok);
        if (!ok) valido = false;
      });
      if (!valido) {
        avisar("Confira os campos destacados.", "falha");
        form.querySelector("[aria-invalid=true]")?.focus();
        return;
      }

      const dados = Object.fromEntries(new FormData(form));
      delete dados._honey;
      const assunto = S.contato.assunto || `Novo contato pelo site ${S.nome || ""}`;
      let url, corpo;
      if (S.contato.web3formsChave) {
        url = "https://api.web3forms.com/submit";
        corpo = { access_key: S.contato.web3formsChave, subject: assunto, from_name: S.nome, ...dados };
      } else {
        url = `https://formsubmit.co/ajax/${encodeURIComponent(S.contato.email)}`;
        corpo = { _subject: assunto, _template: "table", _captcha: "false", ...dados };
      }

      botao.disabled = true;
      avisar("Enviando…");
      try {
        const r = await fetch(url, {
          method: "POST",
          headers: { "Content-Type": "application/json", Accept: "application/json" },
          body: JSON.stringify(corpo),
        });
        const j = await r.json().catch(() => ({}));
        if (!r.ok || !(j.success === true || j.success === "true")) throw new Error(j.message || r.status);
        form.reset();
        avisar("Solicitação enviada. A secretaria responderá pelos contatos informados.", "ok");
      } catch (e) {
        console.warn("Falha no envio do formulário:", e);
        avisar("Não foi possível enviar agora. Chame a gente no WhatsApp.", "falha");
      } finally {
        botao.disabled = false;
      }
    });
  }
})();
