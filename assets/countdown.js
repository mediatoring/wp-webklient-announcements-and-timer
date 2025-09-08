(function () {
    function pad(n) { return n < 10 ? '0' + n : '' + n; }
    function fmt(ms) {
      if (ms <= 0) return null;
      var s = Math.floor(ms / 1000);
      var d = Math.floor(s / 86400);
      var h = Math.floor((s % 86400) / 3600);
      var m = Math.floor((s % 3600) / 60);
      var sec = s % 60;
      var dpart = d > 0 ? d + ' d ' : '';
      return dpart + pad(h) + ':' + pad(m) + ':' + pad(sec);
    }
  
    function mount(html) {
      var mountSel = (window.WPA_DATA && window.WPA_DATA.mount_sel) || '#wpa-announcement-mount';
      var mount = document.querySelector(mountSel);
      if (!mount) return null;
      mount.innerHTML = '<div class="wpa-banner-wrap">' + html + '</div>';
      return mount.querySelector('.wpa-banner');
    }
  
    function startTimer(root, serverNowSec, deadlineSec) {
      var daysEl = root.querySelector('#wpa-countdown-days');
      var hoursEl = root.querySelector('#wpa-countdown-hours');
      var minutesEl = root.querySelector('#wpa-countdown-minutes');
      var secondsEl = root.querySelector('#wpa-countdown-seconds');
      var skew = Date.now() - (serverNowSec * 1000); // kladné = klient jde napřed
      
      function tick() {
        var nowMs = Date.now() - skew;
        var diff = (deadlineSec * 1000) - nowMs;
        
        if (diff <= 0) {
          // po konci vyčistíme DOM a už neobnovujeme
          var wrap = root.closest('.wpa-banner-wrap') || root;
          if (wrap && wrap.parentNode) wrap.parentNode.innerHTML = '';
          window.clearInterval(intId);
          return;
        }
        
        var s = Math.floor(diff / 1000);
        var d = Math.floor(s / 86400);
        var h = Math.floor((s % 86400) / 3600);
        var m = Math.floor((s % 3600) / 60);
        var sec = s % 60;
        
        if (daysEl) daysEl.textContent = pad(d);
        if (hoursEl) hoursEl.textContent = pad(h);
        if (minutesEl) minutesEl.textContent = pad(m);
        if (secondsEl) secondsEl.textContent = pad(sec);
      }
      
      tick();
      var intId = window.setInterval(tick, 1000);
    }
  
    function fetchAnnouncement(cb) {
      var ajaxUrl = (window.WPA_DATA && window.WPA_DATA.ajax_url) || '/wp-admin/admin-ajax.php';
      var action = (window.WPA_DATA && window.WPA_DATA.action) || 'wpa_get_announcement';
      var url = ajaxUrl + '?action=' + encodeURIComponent(action);
  
      var xhr = new XMLHttpRequest();
      xhr.open('GET', url, true);
      xhr.onreadystatechange = function () {
        if (xhr.readyState === 4) {
          try {
            var res = JSON.parse(xhr.responseText);
            if (res && res.success) cb(null, res.data);
            else cb(new Error('Invalid response'));
          } catch (e) {
            cb(e);
          }
        }
      };
      xhr.send();
    }
  
    function init() {
      fetchAnnouncement(function (err, data) {
        if (err || !data || !data.active) return;
        var root = mount(data.html);
        if (!root) return;
        startTimer(root, data.now, data.deadline);
      });
    }
  
    // Spustíme hned, pokud je DOM už připravený, jinak počkáme
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', init);
    } else {
      init();
    }
  })();
  