</div>
    </div>

  <?php
  $isDesigno = $isDesigno ?? (($shellStyle ?? '') === 'designo');
  if ($isDesigno && !empty($navLinks)):
      $bottomNav = $mobileNavLinks ?? $navLinks;
      $bottomMore = false;
      if (count($bottomNav) > 5) {
          $bottomNav = array_slice($navLinks, 0, 4);
          $bottomMore = true;
      }
  ?>
    <nav class="dg-bottom-nav" aria-label="Primary">
      <?php foreach ($bottomNav as $link): ?>
        <a class="dg-bottom-link<?= ($activeNav ?? '') === ($link['id'] ?? '') ? ' is-active' : '' ?>"
           href="<?= h($link['href']) ?>">
          <span class="dg-bottom-ico" aria-hidden="true"><?= h($link['icon'] ?? '•') ?></span>
          <span class="dg-bottom-label"><?= h($link['label']) ?></span>
        </a>
      <?php endforeach; ?>
      <?php if ($bottomMore): ?>
        <button type="button" class="dg-bottom-link dg-bottom-more" id="dgBottomMore" aria-label="More menu">
          <span class="dg-bottom-ico" aria-hidden="true">☰</span>
          <span class="dg-bottom-label">More</span>
        </button>
      <?php endif; ?>
    </nav>
  <?php endif; ?>

  </div>
  <script>
    (function () {
      const toggle = document.getElementById('menuToggle');
      const nav = document.getElementById('navbarNav');
      const overlay = document.getElementById('overlay');
      const shell = document.getElementById('appShell');
      const isDesigno = document.body.classList.contains('is-designo');
      const bottomMore = document.getElementById('dgBottomMore');

      const closeNav = () => {
        nav?.classList.remove('is-open');
        shell?.classList.remove('is-side-open');
        overlay?.classList.remove('show');
      };
      const openNav = () => {
        if (isDesigno) {
          shell?.classList.add('is-side-open');
        } else {
          nav?.classList.add('is-open');
        }
        overlay?.classList.add('show');
        document.querySelectorAll('details.search-menu[open], details.notif-menu[open], details.profile-menu[open]').forEach((d) => d.removeAttribute('open'));
      };

      toggle?.addEventListener('click', () => {
        const open = isDesigno ? shell?.classList.contains('is-side-open') : nav?.classList.contains('is-open');
        open ? closeNav() : openNav();
      });
      bottomMore?.addEventListener('click', () => {
        const open = shell?.classList.contains('is-side-open');
        open ? closeNav() : openNav();
      });
      overlay?.addEventListener('click', closeNav);
      nav?.querySelectorAll('a').forEach((a) => a.addEventListener('click', closeNav));
      document.querySelectorAll('.designo-side-link').forEach((a) => a.addEventListener('click', closeNav));

      document.addEventListener('click', (e) => {
        document.querySelectorAll('details.menu-dots[open], details.profile-menu[open], details.search-menu[open], details.notif-menu[open]').forEach((d) => {
          if (!d.contains(e.target)) d.removeAttribute('open');
        });
      });

      const searchInput = document.getElementById('globalSearch') || document.getElementById('globalSearchInline');
      const searchResults = document.getElementById('searchResults') || document.getElementById('searchResultsInline');
      const searchMenu = document.getElementById('searchMenu');
      let searchTimer = null;

      const renderSearch = (items) => {
        if (!searchResults) return;
        searchResults.hidden = false;
        if (!items.length) {
          searchResults.innerHTML = '<div class="search-empty">No matches</div>';
          return;
        }
        let html = '';
        let last = '';
        items.forEach((r) => {
          if (r.group !== last) {
            html += `<div class="search-group">${r.group}</div>`;
            last = r.group;
          }
          html += `<a class="search-item" href="${r.href}"><strong>${r.title}</strong><span>${r.subtitle || ''}</span></a>`;
        });
        searchResults.innerHTML = html;
      };

      searchMenu?.addEventListener('toggle', () => {
        if (searchMenu.open) {
          setTimeout(() => searchInput?.focus(), 50);
        }
      });

      searchInput?.addEventListener('input', () => {
        clearTimeout(searchTimer);
        const q = searchInput.value.trim();
        if (q.length < 1) {
          if (searchResults) {
            searchResults.innerHTML = '<div class="search-empty">Type to search</div>';
            if (searchResults.id === 'searchResultsInline') searchResults.hidden = true;
          }
          return;
        }
        searchTimer = setTimeout(async () => {
          try {
            const res = await fetch('/teacher-traking/api/search.php?q=' + encodeURIComponent(q));
            const data = await res.json();
            renderSearch(data.results || []);
          } catch (e) {
            searchResults.innerHTML = '<div class="search-empty">Search failed</div>';
            searchResults.hidden = false;
          }
        }, 220);
      });

      searchInput?.addEventListener('focus', () => {
        if (searchInput.value.trim().length >= 1 && searchResults?.id === 'searchResultsInline') {
          searchResults.hidden = false;
        }
      });

      document.addEventListener('keydown', (e) => {
        const key = (e.key || '').toLowerCase();
        if ((e.ctrlKey || e.metaKey) && key === 'k') {
          e.preventDefault();
          searchInput?.focus();
          searchInput?.select();
          if (searchMenu && !searchMenu.open) searchMenu.setAttribute('open', '');
        }
        if (key === 'escape' && searchResults?.id === 'searchResultsInline') {
          searchResults.hidden = true;
          searchInput?.blur();
        }
      });

      document.addEventListener('click', (e) => {
        const wrap = document.querySelector('.designo-search-wrap');
        if (wrap && !wrap.contains(e.target) && searchResults?.id === 'searchResultsInline') {
          searchResults.hidden = true;
        }
      });

      const notifMenu = document.getElementById('notifMenu');
      const notifList = document.getElementById('notifList');
      const notifBadge = document.getElementById('notifBadge');
      const markAllBtn = document.getElementById('markAllRead');

      const setBadge = (n) => {
        if (!notifBadge) return;
        if (n > 0) {
          notifBadge.textContent = n > 9 ? '9+' : String(n);
          notifBadge.classList.add('is-on');
        } else {
          notifBadge.textContent = '';
          notifBadge.classList.remove('is-on');
        }
      };

      const loadNotifs = async () => {
        if (!notifList) return;
        try {
          const res = await fetch('/teacher-traking/api/notifications.php?limit=12');
          const data = await res.json();
          setBadge(data.unread || 0);
          if (!data.items || !data.items.length) {
            notifList.innerHTML = '<div class="search-empty">No notifications yet</div>';
            return;
          }
          notifList.innerHTML = data.items.map((n) => `
            <a class="notif-item${n.is_read ? '' : ' is-unread'}" href="${n.href}" data-id="${n.id}">
              <div>
                <strong>${n.title}</strong>
                <span>${n.body}</span>
              </div>
              <time title="${n.date_label}">${n.when}</time>
            </a>
          `).join('');
        } catch (e) {
          notifList.innerHTML = '<div class="search-empty">Could not load</div>';
        }
      };

      notifMenu?.addEventListener('toggle', () => {
        if (notifMenu.open) loadNotifs();
      });

      notifList?.addEventListener('click', async (e) => {
        const a = e.target.closest('a.notif-item');
        if (!a) return;
        const id = a.getAttribute('data-id');
        try {
          const fd = new FormData();
          fd.append('action', 'mark_read');
          fd.append('id', id);
          await fetch('/teacher-traking/api/notifications.php', { method: 'POST', body: fd });
        } catch (err) {}
      });

      markAllBtn?.addEventListener('click', async (e) => {
        e.preventDefault();
        const fd = new FormData();
        fd.append('action', 'mark_read');
        fd.append('id', '0');
        try {
          const res = await fetch('/teacher-traking/api/notifications.php', { method: 'POST', body: fd });
          const data = await res.json();
          setBadge(data.unread || 0);
          loadNotifs();
        } catch (err) {}
      });

      setInterval(async () => {
        try {
          const res = await fetch('/teacher-traking/api/notifications.php?limit=1');
          const data = await res.json();
          setBadge(data.unread || 0);
        } catch (e) {}
      }, 45000);
    })();
  </script>
</body>
</html>
