/**
 * MEDICO API client + auth helpers
 */
(function (global) {
  // Use index.php so it works even if Apache rewrite is off
  const BASE = '/teacher-traking/api/index.php';
  const TOKEN_KEY = 'medico_token';
  const USER_KEY = 'medico_user';
  const PROFILE_KEY = 'medico_profile';
  const API_TIMEOUT_MS = 12000;

  function getToken() {
    return localStorage.getItem(TOKEN_KEY);
  }

  function saveSession({ token, user, profile }) {
    localStorage.setItem(TOKEN_KEY, token);
    localStorage.setItem(USER_KEY, JSON.stringify(user));
    if (profile) localStorage.setItem(PROFILE_KEY, JSON.stringify(profile));
  }

  function clearSession() {
    localStorage.removeItem(TOKEN_KEY);
    localStorage.removeItem(USER_KEY);
    localStorage.removeItem(PROFILE_KEY);
  }

  function currentUser() {
    try {
      return JSON.parse(localStorage.getItem(USER_KEY) || 'null');
    } catch {
      return null;
    }
  }

  function currentProfile() {
    try {
      return JSON.parse(localStorage.getItem(PROFILE_KEY) || 'null');
    } catch {
      return null;
    }
  }

  async function api(path, options = {}) {
    const headers = Object.assign(
      { Accept: 'application/json' },
      options.headers || {}
    );

    const token = getToken();
    if (token) headers.Authorization = `Bearer ${token}`;

    let body = options.body;
    if (body && !(body instanceof FormData) && typeof body === 'object') {
      headers['Content-Type'] = 'application/json';
      body = JSON.stringify(body);
    }

    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), API_TIMEOUT_MS);

    try {
      const res = await fetch(`${BASE}${path.startsWith('/') ? path : '/' + path}`, {
        method: options.method || 'GET',
        headers,
        body,
        signal: controller.signal,
      });

      let data;
      const text = await res.text();
      try {
        data = text ? JSON.parse(text) : { success: false, message: 'Empty server response' };
      } catch {
        data = {
          success: false,
          message: res.ok
            ? 'Invalid server response'
            : `Server error (${res.status}). Check Apache/PHP and database.`,
        };
      }

      if (res.status === 401) {
        clearSession();
        if (!location.pathname.includes('login') && !location.pathname.includes('register')) {
          location.href = '/login.html';
        }
      }

      return { ok: res.ok && data.success !== false, status: res.status, data };
    } catch (err) {
      const aborted = err && err.name === 'AbortError';
      return {
        ok: false,
        status: 0,
        data: {
          success: false,
          message: aborted
            ? 'Request timed out. Start MySQL in XAMPP, then open setup.php once.'
            : 'Cannot reach API. Start Apache in XAMPP and try again.',
        },
      };
    } finally {
      clearTimeout(timer);
    }
  }

  function requireAuth(roles) {
    const user = currentUser();
    if (!user || !getToken()) {
      location.href = '/login.html';
      return null;
    }
    if (roles && !roles.includes(user.role)) {
      location.href = '/index.html';
      return null;
    }
    return user;
  }

  function roleHome(role) {
    switch (role) {
      case 'teacher':
        return '/teacher-traking/teacher/index.html';
      case 'branch_manager':
        return '/teacher-traking/manager/index.html';
      case 'admin':
        return '/teacher-traking/admin/index.html';
      case 'super_admin':
        return '/teacher-traking/super/index.html';
      default:
        return '/login.html';
    }
  }

  function formatTime(t) {
    if (!t) return '—';
    const parts = String(t).slice(0, 5).split(':');
    let h = parseInt(parts[0], 10);
    const m = parts[1];
    const ampm = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    return `${h}:${m} ${ampm}`;
  }

  function formatDate(d) {
    if (!d) return '—';
    return new Date(d + 'T00:00:00').toLocaleDateString('en-GB', {
      day: 'numeric',
      month: 'short',
      year: 'numeric',
    });
  }

  function badge(status) {
    const s = String(status || 'pending').replace(/\s/g, '_');
    return `<span class="badge badge-${s}">${String(status).replace(/_/g, ' ')}</span>`;
  }

  function initials(name) {
    return String(name || 'M')
      .split(/\s+/)
      .slice(0, 2)
      .map((w) => w[0] || '')
      .join('')
      .toUpperCase();
  }

  function showAlert(el, message, type = 'error') {
    if (!el) return;
    el.className = `alert alert-${type} show`;
    el.textContent = message;
  }

  function showSkeleton(root) {
    const el = typeof root === 'string' ? document.querySelector(root) : root;
    if (!el) return;
    el.classList.add('is-loading');
    el.setAttribute('aria-busy', 'true');
  }

  function hideSkeleton(root) {
    const el = typeof root === 'string' ? document.querySelector(root) : root;
    if (!el) return;
    el.classList.remove('is-loading');
    el.removeAttribute('aria-busy');
  }

  function setButtonLoading(btn, loading, idleLabel) {
    if (!btn) return;
    if (loading) {
      btn.dataset.label = btn.dataset.label || btn.textContent;
      btn.disabled = true;
      btn.classList.add('btn-loading');
      btn.innerHTML = '<span class="btn-spinner"></span> Please wait…';
    } else {
      btn.disabled = false;
      btn.classList.remove('btn-loading');
      btn.textContent = idleLabel || btn.dataset.label || 'Submit';
    }
  }

  function getLocation() {
    return new Promise((resolve, reject) => {
      if (!navigator.geolocation) {
        reject(new Error('Geolocation not supported on this device'));
        return;
      }
      navigator.geolocation.getCurrentPosition(
        (pos) =>
          resolve({
            latitude: pos.coords.latitude,
            longitude: pos.coords.longitude,
            accuracy: pos.coords.accuracy,
          }),
        (err) => reject(err),
        { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
      );
    });
  }

  function bindShell() {
    const toggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('overlay');
    if (!toggle || !sidebar) return;

    const close = () => {
      sidebar.classList.remove('open');
      overlay?.classList.remove('show');
    };
    const open = () => {
      sidebar.classList.add('open');
      overlay?.classList.add('show');
    };

    toggle.addEventListener('click', () => {
      if (sidebar.classList.contains('open')) close();
      else open();
    });
    overlay?.addEventListener('click', close);

    document.getElementById('logoutBtn')?.addEventListener('click', (e) => {
      e.preventDefault();
      clearSession();
      location.href = '/login.html';
    });
  }

  global.Medico = {
    api,
    saveSession,
    clearSession,
    currentUser,
    currentProfile,
    requireAuth,
    roleHome,
    formatTime,
    formatDate,
    badge,
    initials,
    showAlert,
    showSkeleton,
    hideSkeleton,
    setButtonLoading,
    getLocation,
    bindShell,
  };
})(window);
