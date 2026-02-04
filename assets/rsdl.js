
(function(){
  function ready(fn){ if(document.readyState!=='loading'){fn();} else {document.addEventListener('DOMContentLoaded',fn);} }

  ready(function(){
    document.querySelectorAll('[data-rsdl-calendar]').forEach(function(cal){
      cal.addEventListener('click', function(e){
        var cell = e.target.closest('[data-slot-id]');
        if(!cell) return;
        var slotId = cell.getAttribute('data-slot-id');
        var form = cal.closest('form');
        var cb = form ? form.querySelector('input.rsdl-slot-cb[value="'+slotId+'"]') : null;
        if(!cb) return;
        cb.checked = !cb.checked;
        cell.classList.toggle('rsdl-selected', cb.checked);
      });

      var dragging = false;
      var dragMode = null;

      cal.addEventListener('mousedown', function(e){
        var cell = e.target.closest('[data-slot-id]');
        if(!cell) return;
        var slotId = cell.getAttribute('data-slot-id');
        var form = cal.closest('form');
        var cb = form ? form.querySelector('input.rsdl-slot-cb[value="'+slotId+'"]') : null;
        if(!cb) return;
        dragging = true;
        dragMode = cb.checked ? 'deselect' : 'select';
        e.preventDefault();
      });

      document.addEventListener('mouseup', function(){ dragging=false; dragMode=null; });

      cal.addEventListener('mouseover', function(e){
        if(!dragging || !dragMode) return;
        var cell = e.target.closest('[data-slot-id]');
        if(!cell) return;
        var slotId = cell.getAttribute('data-slot-id');
        var form = cal.closest('form');
        var cb = form ? form.querySelector('input.rsdl-slot-cb[value="'+slotId+'"]') : null;
        if(!cb) return;
        var should = (dragMode === 'select');
        if(cb.checked !== should){
          cb.checked = should;
          cell.classList.toggle('rsdl-selected', should);
        }
      });
    });
  });
})();


// Slot picker for creator form
(function(){
  function pad(n){ return (n<10?'0':'')+n; }
  function parseDate(str){
    // str: YYYY-MM-DD
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(str||'');
    if(!m) return null;
    return new Date(Number(m[1]), Number(m[2])-1, Number(m[3]));
  }
  function parseTime(str){
    var m = /^(\d{2}):(\d{2})$/.exec(str||'');
    if(!m) return null;
    return {h:Number(m[1]), m:Number(m[2])};
  }
  function formatYMD(d){
    return d.getFullYear()+'-'+pad(d.getMonth()+1)+'-'+pad(d.getDate());
  }
  function formatHM(mins){
    var h=Math.floor(mins/60), m=mins%60;
    return pad(h)+':'+pad(m);
  }
  function ready(fn){ if(document.readyState!=='loading'){fn();} else {document.addEventListener('DOMContentLoaded',fn);} }

  ready(function(){
    document.querySelectorAll('[data-rsdl-slot-picker]').forEach(function(picker){
      var form = picker.closest('form');
      if(!form) return;

      var startEl = form.querySelector('input[name="start_date"]');
      var endEl   = form.querySelector('input[name="end_date"]');
      var dsEl    = form.querySelector('input[name="day_start"]');
      var deEl    = form.querySelector('input[name="day_end"]');
      var durEl   = form.querySelector('select[name="slot_duration"]');
      var stepEl  = form.querySelector('select[name="slot_step"]');
      var hidden  = form.querySelector('#rsdl_manual_slots_json');

      var selected = new Set(); // items "YYYY-MM-DD HH:MM"

      function build(){
        var sd=parseDate(startEl && startEl.value);
        var ed=parseDate(endEl && endEl.value);
        var ds=parseTime(dsEl && dsEl.value);
        var de=parseTime(deEl && deEl.value);
        var dur=Number(durEl && durEl.value || 60);
        var step=Number(stepEl && stepEl.value || dur);

        if(!sd || !ed || !ds || !de || sd>ed){
          picker.innerHTML = '<div class="rsdl-small rsdl-muted">Selecciona fecha inicio/fin para ver el calendario.</div>';
          return;
        }

        var minT = ds.h*60 + ds.m;
        var maxT = de.h*60 + de.m;
        if(maxT<=minT){ picker.innerHTML='<div class="rsdl-small rsdl-muted">Rango horario inválido.</div>'; return; }

        // dates array
        var dates=[];
        var cur=new Date(sd.getTime());
        while(cur<=ed){
          dates.push(new Date(cur.getTime()));
          cur.setDate(cur.getDate()+1);
        }

        // times
        var times=[];
        for(var t=minT; t+dur<=maxT; t+=Math.max(1,step)){
          times.push(t);
        }

        // render grid
        var html='';
        html+='<div class="rsdl-gcal-wrap" data-rsdl-calendar data-rsdl-picker-grid>';
        html+='<div class="rsdl-gcal-head"><div class="rsdl-gcal-timehead"></div>';
        dates.forEach(function(d){
          var label=d.toLocaleDateString(undefined,{weekday:'short', day:'2-digit', month:'2-digit'});
          html+='<div class="rsdl-gcal-dayhead">'+label+'</div>';
        });
        html+='</div><div class="rsdl-gcal-body">';

        times.forEach(function(t){
          html+='<div class="rsdl-gcal-row">';
          html+='<div class="rsdl-gcal-time">'+formatHM(t)+'</div>';
          dates.forEach(function(d){
            var key = formatYMD(d)+' '+formatHM(t);
            var cls = 'rsdl-gcal-cell rsdl-available';
            if(selected.has(key)) cls += ' rsdl-selected';
            html+='<div class="'+cls+'" data-slot-id="'+key+'" title="'+key+'"></div>';
          });
          html+='</div>';
        });

        html+='</div></div>';
        picker.innerHTML = html;

        // wire click/drag selection on this grid
        var grid = picker.querySelector('[data-rsdl-picker-grid]');
        if(!grid) return;

        grid.addEventListener('click', function(e){
          var cell=e.target.closest('[data-slot-id]');
          if(!cell) return;
          var key=cell.getAttribute('data-slot-id');
          if(selected.has(key)){ selected.delete(key); cell.classList.remove('rsdl-selected'); }
          else { selected.add(key); cell.classList.add('rsdl-selected'); }
          syncHidden();
        });

        var dragging=false, dragMode=null;
        grid.addEventListener('mousedown', function(e){
          var cell=e.target.closest('[data-slot-id]');
          if(!cell) return;
          var key=cell.getAttribute('data-slot-id');
          dragging=true;
          dragMode = selected.has(key) ? 'deselect' : 'select';
          e.preventDefault();
        });
        document.addEventListener('mouseup', function(){ dragging=false; dragMode=null; });
        grid.addEventListener('mouseover', function(e){
          if(!dragging || !dragMode) return;
          var cell=e.target.closest('[data-slot-id]');
          if(!cell) return;
          var key=cell.getAttribute('data-slot-id');
          var should = (dragMode==='select');
          if(should && !selected.has(key)){ selected.add(key); cell.classList.add('rsdl-selected'); syncHidden(); }
          if(!should && selected.has(key)){ selected.delete(key); cell.classList.remove('rsdl-selected'); syncHidden(); }
        });
      }

      function syncHidden(){
        if(!hidden) return;
        hidden.value = JSON.stringify(Array.from(selected));
      }

      // rebuild on input changes
      [startEl,endEl,dsEl,deEl,durEl,stepEl].forEach(function(el){
        if(!el) return;
        el.addEventListener('change', build);
        el.addEventListener('input', build);
      });

      build();
    });
  });
})();


// Vote grid: cycle states yes/maybe/none, drag selects yes
(function(){
  function ready(fn){ if(document.readyState!=='loading'){fn();} else {document.addEventListener('DOMContentLoaded',fn);} }

  function setState(cell, state){
    cell.setAttribute('data-state', state || '');
    cell.classList.toggle('rsdl-state-yes', state === 'yes');
    cell.classList.toggle('rsdl-state-maybe', state === 'maybe');
    var slotId = cell.getAttribute('data-slot-id');
    var form = cell.closest('form');
    if(!form) return;
    var hidden = form.querySelector('input.rsdl-slot-state[data-slot-id="'+slotId+'"]');
    if(hidden){ hidden.value = (state === 'yes' || state === 'maybe') ? state : ''; }
  }

  function nextState(state){
    if(state === 'yes') return 'maybe';
    if(state === 'maybe') return '';
    return 'yes';
  }

  ready(function(){
    document.querySelectorAll('[data-rsdl-vote-grid]').forEach(function(cal){
      cal.addEventListener('click', function(e){
        var cell = e.target.closest('[data-slot-id]');
        if(!cell) return;
        var cur = cell.getAttribute('data-state') || '';
        setState(cell, nextState(cur));
      });

      var dragging=false, dragMode=null; // yes or clear
      cal.addEventListener('mousedown', function(e){
        var cell = e.target.closest('[data-slot-id]');
        if(!cell) return;
        dragging=true;
        var cur = cell.getAttribute('data-state') || '';
        dragMode = (cur === 'yes') ? 'clear' : 'yes';
        e.preventDefault();
      });
      document.addEventListener('mouseup', function(){ dragging=false; dragMode=null; });
      cal.addEventListener('mouseover', function(e){
        if(!dragging || !dragMode) return;
        var cell = e.target.closest('[data-slot-id]');
        if(!cell) return;
        setState(cell, dragMode === 'yes' ? 'yes' : '');
      });
    });
  });
})();
