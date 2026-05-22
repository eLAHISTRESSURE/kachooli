// Kacooli - Premium Women's Lingerie E-Commerce Platform
// Main JavaScript File - Production Ready

// ==================== INITIALIZATION ====================
document.addEventListener('DOMContentLoaded', initializeApp);

function initializeApp() {
  setupMobileMenu();
  setupAlerts();
  setupCartCounter();
  setupWishlistCounter();
  setupImageBlurring();
  setupFormValidation();
  setupEventListeners();
}

// ==================== MOBILE MENU ====================
function setupMobileMenu() {
  const burgerToggle = document.getElementById('burgerToggle');
  const burgerMenu = document.getElementById('burgerMenu');
  const closeBurger = document.getElementById('closeBurger');
  const burgerMenuItems = document.querySelectorAll('.burger-menu-items a, .burger-menu-items button');

  if (!burgerToggle || !burgerMenu) return;

  burgerToggle.addEventListener('click', () => {
    burgerMenu.classList.add('active');
    burgerToggle.classList.add('active');
    document.body.style.overflow = 'hidden';
  });

  closeBurger?.addEventListener('click', closeMobileMenu);

  burgerMenu.addEventListener('click', (e) => {
    if (e.target === burgerMenu) closeMobileMenu();
  });

  burgerMenuItems.forEach(item => {
    item.addEventListener('click', closeMobileMenu);
  });

  function closeMobileMenu() {
    burgerMenu.classList.remove('active');
    burgerToggle.classList.remove('active');
    document.body.style.overflow = 'auto';
  }
}

// ==================== ALERT HANDLING ====================
function setupAlerts() {
  const alerts = document.querySelectorAll('.alert');
  alerts.forEach(alert => {
    const closeBtn = alert.querySelector('.close-alert');
    if (closeBtn) {
      closeBtn.addEventListener('click', () => {
        alert.style.animation = 'slideUp 300ms ease';
        setTimeout(() => alert.remove(), 300);
      });
    }
    // Auto dismiss after 5 seconds
    setTimeout(() => {
      if (alert.parentElement) {
        alert.style.animation = 'slideUp 300ms ease';
        setTimeout(() => alert.remove(), 300);
      }
    }, 5000);
  });
}

// ==================== CART MANAGEMENT ====================
function setupCartCounter() {
  updateCartCount();
}

function updateCartCount() {
  fetch('/api/cart/count')
    .then(res => res.json())
    .catch(() => ({ count: 0 }))
    .then(data => {
      const counters = document.querySelectorAll('.cart-count');
      counters.forEach(counter => {
        counter.textContent = data.count || '0';
      });
    });
}

function addToCart(productId, quantity = 1, size = '', color = '') {
  fetch('/api/cart/add', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      product_id: productId,
      quantity: quantity,
      size: size,
      color: color
    })
  })
    .then(res => res.json())
    .then(data => {
      if (data.success) {
        showNotification('Product added to cart!', 'success');
        updateCartCount();
      } else {
        showNotification(data.error || 'Error adding to cart', 'danger');
      }
    })
    .catch(() => showNotification('Error adding to cart', 'danger'));
}

// ==================== WISHLIST MANAGEMENT ====================
function setupWishlistCounter() {
  updateWishlistCount();
  setupWishlistButtons();
}

function updateWishlistCount() {
  fetch('/api/wishlist/count')
    .then(res => res.json())
    .catch(() => ({ count: 0 }))
    .then(data => {
      const counters = document.querySelectorAll('.wishlist-count');
      counters.forEach(counter => {
        counter.textContent = data.count || '0';
      });
    });
}

function setupWishlistButtons() {
  const wishlistBtns = document.querySelectorAll('[data-wishlist-btn]');
  wishlistBtns.forEach(btn => {
    btn.addEventListener('click', toggleWishlist);
  });
}

function toggleWishlist(e) {
  e.preventDefault();
  const btn = e.currentTarget;
  const productId = btn.dataset.productId;
  const isAdded = btn.classList.contains('active');

  const endpoint = isAdded ? '/api/wishlist/remove' : '/api/wishlist/add';

  fetch(endpoint, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({ product_id: parseInt(productId) })
  })
    .then(res => res.json())
    .then(data => {
      if (data.success) {
        btn.classList.toggle('active');
        const action = isAdded ? 'removed from' : 'added to';
        showNotification(`Product ${action} wishlist!`, 'success');
        updateWishlistCount();
      }
    })
    .catch(() => showNotification('Error updating wishlist', 'danger'));
}

// ==================== IMAGE BLURRING FOR NON-LOGGED IN USERS ====================
function setupImageBlurring() {
  fetch('/api/image-access')
    .then(res => res.json())
    .then(data => {
      if (data.blur) {
        blurProductImages();
        showLoginPrompt();
      }
    });
}

function blurProductImages() {
  const images = document.querySelectorAll('[data-blur-if-logged="false"]');
  images.forEach(img => {
    // Use the CSS-ready class and dataset flag the stylesheet recognizes
    img.classList.add('is-blurred');
    img.setAttribute('data-blur', 'true');
  });
}

function showLoginPrompt() {
  const loginBtns = document.querySelectorAll('[data-require-login]');
  loginBtns.forEach(btn => {
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      showNotification('Please log in to view and purchase products', 'warning');
      window.location.href = '/login?next=' + window.location.pathname;
    });
  });
}

// ==================== FORM VALIDATION ====================
function setupFormValidation() {
  const forms = document.querySelectorAll('form');
  forms.forEach(form => {
    form.addEventListener('submit', validateFormOnSubmit);
  });
}

function validateFormOnSubmit(e) {
  const inputs = this.querySelectorAll('input[required], textarea[required], select[required]');
  let isValid = true;

  inputs.forEach(input => {
    if (!input.value.trim()) {
      markFieldError(input, 'This field is required');
      isValid = false;
    } else {
      clearFieldError(input);
    }

    // Email validation
    if (input.type === 'email' && !isValidEmail(input.value)) {
      markFieldError(input, 'Invalid email address');
      isValid = false;
    }

    // Phone validation
    if (input.type === 'tel' && !isValidPhone(input.value)) {
      markFieldError(input, 'Invalid phone number');
      isValid = false;
    }
  });

  if (!isValid) {
    e.preventDefault();
  }
}

function markFieldError(field, message) {
  field.classList.add('error');
  const errorEl = field.parentElement.querySelector('.error-text');
  if (errorEl) {
    errorEl.textContent = message;
  }
}

function clearFieldError(field) {
  field.classList.remove('error');
  const errorEl = field.parentElement.querySelector('.error-text');
  if (errorEl) {
    errorEl.textContent = '';
  }
}

function isValidEmail(email) {
  const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  return re.test(email);
}

function isValidPhone(phone) {
  const re = /^[\+]?[0-9\-]{7,}$/;
  return re.test(phone.replace(/\s/g, ''));
}

// ==================== GENERAL EVENT LISTENERS ====================
function setupEventListeners() {
  // Newsletter subscription
  const newsForms = document.querySelectorAll('#newsletterForm, #exitNewsletterForm');
  newsForms.forEach(form => {
    form.addEventListener('submit', handleNewsletterSubmit);
  });

  // Add to cart buttons
  const cartBtns = document.querySelectorAll('[data-add-to-cart]');
  cartBtns.forEach(btn => {
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      const productId = btn.dataset.productId;
      addToCart(productId);
    });
  });

  // Dropdown menus
  setupDropdowns();

  // Lazy loading for images
  setupLazyLoading();
}

function handleNewsletterSubmit(e) {
  e.preventDefault();
  const form = this;
  const emailInput = form.querySelector('input[type="email"]');
  const email = emailInput ? emailInput.value.trim() : '';
  
  if (!email || !isValidEmail(email)) {
    showNotification('Please enter a valid email address', 'danger');
    return;
  }
  
  fetch('/api/newsletter/subscribe', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({ email: email })
  })
  .then(res => res.json())
  .then(data => {
    if (data.success) {
      showNotification(data.message || 'Thank you for subscribing!', 'success');
      form.reset();
    } else {
      showNotification(data.error || 'Subscription failed', 'danger');
    }
  })
  .catch(err => {
    console.error(err);
    showNotification('An error occurred. Please try again.', 'danger');
  });
}

function setupDropdowns() {
  const dropdowns = document.querySelectorAll('.dropdown');
  dropdowns.forEach(dropdown => {
    const toggle = dropdown.querySelector('.nav-item');
    if (toggle) {
      toggle.addEventListener('click', (e) => {
        if (window.innerWidth <= 767) {
          e.preventDefault();
          dropdown.classList.toggle('is-open');
        }
      });
    }
  });

  // Close dropdowns on outside click
  document.addEventListener('click', (e) => {
    if (!e.target.closest('.dropdown')) {
      document.querySelectorAll('.dropdown.is-open').forEach(d => {
        d.classList.remove('is-open');
      });
    }
  });
}

function setupLazyLoading() {
  const images = document.querySelectorAll('img[data-src]');
  if ('IntersectionObserver' in window) {
    const imageObserver = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          const img = entry.target;
          img.src = img.dataset.src;
          img.removeAttribute('data-src');
          imageObserver.unobserve(img);
        }
      });
    });
    images.forEach(img => imageObserver.observe(img));
  } else {
    images.forEach(img => {
      img.src = img.dataset.src;
      img.removeAttribute('data-src');
    });
  }
}

// ==================== NOTIFICATIONS ====================
function showNotification(message, type = 'info') {
  const alertClass = `alert-${type}`;
  const alert = document.createElement('div');
  alert.className = `alert ${alertClass}`;
  alert.innerHTML = `
    ${message}
    <button class="close-alert" aria-label="Close alert">✕</button>
  `;

  const container = document.body;
  container.insertBefore(alert, container.firstChild);

  const closeBtn = alert.querySelector('.close-alert');
  closeBtn.addEventListener('click', () => {
    alert.remove();
  });

  setTimeout(() => {
    if (alert.parentElement) {
      alert.remove();
    }
  }, 5000);
}

// ==================== PRODUCT IMAGE GALLERY ====================
function setupProductGallery(containerId) {
  const gallery = document.getElementById(containerId);
  if (!gallery) return;

  const thumbnails = gallery.querySelectorAll('.product-thumb');
  const mainImage = gallery.querySelector('.product-gallery__main img');

  thumbnails.forEach(thumb => {
    thumb.addEventListener('click', () => {
      thumbnails.forEach(t => t.classList.remove('is-active'));
      thumb.classList.add('is-active');
      if (mainImage && thumb.dataset.fullImage) {
        mainImage.src = thumb.dataset.fullImage;
      }
    });
  });
}

// ==================== SIZE GUIDE MODAL ====================
function openSizeGuideModal() {
  // This will be triggered from product detail page
  showNotification('Size guide feature coming soon!', 'info');
}

// ==================== PRODUCT FILTERS ====================
function setupProductFilters() {
  const filterForm = document.getElementById('productFilters');
  if (!filterForm) return;

  const filters = filterForm.querySelectorAll('input[type="checkbox"], input[type="range"]');
  filters.forEach(filter => {
    filter.addEventListener('change', applyFilters);
  });
}

function applyFilters() {
  const form = document.getElementById('productFilters');
  if (!form) return;

  const formData = new FormData(form);
  const params = new URLSearchParams(formData);

  window.location.href = `/shop?${params.toString()}`;
}

// ==================== SEARCH ====================
const searchInput = document.getElementById('searchInput');
if (searchInput) {
  let searchTimeout;
  searchInput.addEventListener('input', (e) => {
    clearTimeout(searchTimeout);
    const query = e.target.value.trim();

    if (query.length < 2) return;

    searchTimeout = setTimeout(() => {
      // This can be enhanced to show suggestions
      console.log('Search:', query);
    }, 300);
  });
}

// ==================== SCROLL EFFECTS ====================
window.addEventListener('scroll', () => {
  const header = document.querySelector('.header');
  if (window.scrollY > 100) {
    header?.classList.add('scrolled');
  } else {
    header?.classList.remove('scrolled');
  }
});

// ==================== UTILITY FUNCTIONS ====================
function getQueryParam(name) {
  const url = new URLSearchParams(window.location.search);
  return url.get(name);
}

function setQueryParam(name, value) {
  const url = new URL(window.location);
  url.searchParams.set(name, value);
  window.history.pushState({}, '', url);
}

// ==================== ACCESSIBILITY ====================
document.addEventListener('keydown', (e) => {
  // Close mobile menu on Escape
  if (e.key === 'Escape') {
    const burgerMenu = document.getElementById('burgerMenu');
    if (burgerMenu?.classList.contains('active')) {
      burgerMenu.classList.remove('active');
      document.getElementById('burgerToggle')?.classList.remove('active');
    }
  }
});

// ==================== EXPORT FUNCTIONS FOR EXTERNAL USE ====================
window.Kacooli = {
  addToCart: addToCart,
  toggleWishlist: toggleWishlist,
  showNotification: showNotification,
  setupProductGallery: setupProductGallery,
  openSizeGuideModal: openSizeGuideModal,
  setupProductFilters: setupProductFilters
};
(function () {
  var root = document.documentElement;
  var body = document.body;

  function qs(selector, context) {
    return (context || document).querySelector(selector);
  }

  function qsa(selector, context) {
    return Array.prototype.slice.call((context || document).querySelectorAll(selector));
  }

  function toggleClass(el, className) {
    if (!el) return false;
    return el.classList.toggle(className);
  }

  function initMobileMenu() {
    var toggle = qs("#burgerToggle");
    var menu = qs("#burgerMenu");
    var close = qs("#closeBurger");
    if (!toggle || !menu) return;
    toggle.addEventListener("click", function () {
      menu.classList.add("active");
      toggle.classList.add("active");
      body.style.overflow = "hidden";
    });
    if (close) {
      close.addEventListener("click", function () {
        menu.classList.remove("active");
        toggle.classList.remove("active");
        body.style.overflow = "auto";
      });
    }
  }

  function initDropdowns() {
    qsa(".dropdown").forEach(function (dropdown) {
      var toggle = qs(".nav-toggle", dropdown) || qs(".nav-item", dropdown);
      if (!toggle) return;
      toggle.addEventListener("click", function (e) {
        if (window.innerWidth <= 980) {
          e.preventDefault();
          dropdown.classList.toggle("is-open");
        }
      });
    });
    document.addEventListener("click", function (e) {
      if (!e.target.closest(".dropdown")) {
        qsa(".dropdown.is-open").forEach(function (d) {
          d.classList.remove("is-open");
        });
      }
    });
  }

  function initAccordions() {
    qsa("[data-accordion], .accordion").forEach(function (accordion) {
      qsa("[data-accordion-trigger], .accordion-trigger", accordion).forEach(function (trigger) {
        trigger.addEventListener("click", function () {
          var item = trigger.closest("[data-accordion-item], .accordion-item");
          var panel = item ? qs("[data-accordion-panel], .accordion-panel", item) : null;
          var open = toggleClass(item, "is-open");

          trigger.setAttribute("aria-expanded", open ? "true" : "false");
          if (panel) {
            panel.hidden = !open;
          }
        });
      });
    });
  }

  function initSlider(container) {
    var track = qs("[data-slider-track], .slider-track, .carousel-track", container);
    var prev = qs("[data-slider-prev], .slider-prev", container);
    var next = qs("[data-slider-next], .slider-next", container);

    if (!track) return;

    function slide(amount) {
      var slideWidth = track.querySelector("[data-slider-slide], .slider-slide, .carousel-slide");
      var width = slideWidth ? slideWidth.getBoundingClientRect().width : track.clientWidth * 0.8;
      track.scrollBy({ left: amount * width, behavior: "smooth" });
    }

    if (prev) prev.addEventListener("click", function () { slide(-1); });
    if (next) next.addEventListener("click", function () { slide(1); });

    var auto = container.getAttribute("data-slider-auto");
    if (auto && auto !== "false") {
      var intervalMs = parseInt(container.getAttribute("data-slider-interval") || "5000", 10);
      var timer = window.setInterval(function () {
        if (document.hidden) return;
        var maxScrollLeft = track.scrollWidth - track.clientWidth - 2;
        if (track.scrollLeft >= maxScrollLeft) {
          track.scrollTo({ left: 0, behavior: "smooth" });
        } else {
          slide(1);
        }
      }, intervalMs);

      container.addEventListener("mouseenter", function () { window.clearInterval(timer); });
      container.addEventListener("mouseleave", function () {
        timer = window.setInterval(function () {
          if (document.hidden) return;
          var maxScrollLeft = track.scrollWidth - track.clientWidth - 2;
          if (track.scrollLeft >= maxScrollLeft) {
            track.scrollTo({ left: 0, behavior: "smooth" });
          } else {
            slide(1);
          }
        }, intervalMs);
      });
    }
  }

  function initSliders() {
    qsa("[data-slider], .slider, .carousel").forEach(initSlider);
  }

  function getModal() {
    return qs("[data-quickview-modal], .modal-backdrop");
  }

  function openModal(modal, trigger) {
    if (!modal) return;
    modal.setAttribute("aria-hidden", "false");
    modal.classList.add("is-open");
    body.classList.add("modal-open");
    var close = qs("[data-modal-close], .modal-close", modal);
    if (close) close.focus({ preventScroll: true });

    if (trigger) {
      modal.setAttribute("data-last-trigger", trigger.getAttribute("data-product-id") || trigger.id || "");
    }
  }

  function closeModal(modal) {
    if (!modal) return;
    modal.setAttribute("aria-hidden", "true");
    modal.classList.remove("is-open");
    body.classList.remove("modal-open");
  }

  function initQuickView() {
    var modal = getModal();
    if (!modal) return;

    qsa("[data-quickview-trigger], .quick-view-trigger").forEach(function (trigger) {
      trigger.addEventListener("click", function (event) {
        event.preventDefault();
        openModal(modal, trigger);

        var title = trigger.getAttribute("data-product-title");
        var image = trigger.getAttribute("data-product-image");
        var price = trigger.getAttribute("data-product-price");
        var description = trigger.getAttribute("data-product-description");

        var titleNode = qs("[data-quickview-title]", modal);
        var imageNode = qs("[data-quickview-image]", modal);
        var priceNode = qs("[data-quickview-price]", modal);
        var descriptionNode = qs("[data-quickview-description]", modal);

        if (titleNode && title) titleNode.textContent = title;
        if (imageNode && image) imageNode.src = image;
        if (imageNode && title) imageNode.alt = title;
        if (priceNode && price) priceNode.textContent = price;
        if (descriptionNode && description) descriptionNode.textContent = description;
      });
    });

    qsa("[data-modal-close], .modal-close", modal).forEach(function (button) {
      button.addEventListener("click", function () {
        closeModal(modal);
      });
    });

    modal.addEventListener("click", function (event) {
      if (event.target === modal) {
        closeModal(modal);
      }
    });

    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape" && modal.classList.contains("is-open")) {
        closeModal(modal);
      }
    });
  }

  function initWishlistCartPlaceholders() {
    qsa("[data-wishlist-action], .wishlist-trigger").forEach(function (button) {
      button.addEventListener("click", function (event) {
        var active = toggleClass(button, "is-active");
        button.setAttribute("aria-pressed", active ? "true" : "false");
        if (button.hasAttribute("data-placeholder")) {
          event.preventDefault();
          return;
        }
      });
    });

    qsa("[data-cart-action], .cart-trigger").forEach(function (button) {
      button.addEventListener("click", function (event) {
        var target = button.getAttribute("data-placeholder");
        if (target === "true") {
          event.preventDefault();
        }
      });
    });

    qsa("[data-quantity-stepper], .qty-stepper").forEach(function (stepper) {
      var input = qs("input", stepper);
      var min = input && input.getAttribute("min") ? parseInt(input.getAttribute("min"), 10) : 1;
      var max = input && input.getAttribute("max") ? parseInt(input.getAttribute("max"), 10) : null;

      qsa("button", stepper).forEach(function (button) {
        button.addEventListener("click", function () {
          if (!input) return;
          var current = parseInt(input.value || "0", 10) || min;
          if (button.getAttribute("data-step") === "down" || button.classList.contains("qty-down")) {
            current = Math.max(min, current - 1);
          } else {
            current = current + 1;
            if (max !== null) current = Math.min(max, current);
          }
          input.value = String(current);
          input.dispatchEvent(new Event("change", { bubbles: true }));
        });
      });
    });
  }

  function initExitIntent() {
    var popup = qs("[data-exit-popup]");
    if (!popup) return;

    var shown = false;
    var threshold = 16;
    var cooldownKey = "kacooli_exit_popup_seen";
    var seen = false;

    try {
      seen = window.sessionStorage.getItem(cooldownKey) === "1";
    } catch (err) {
      seen = false;
    }

    if (seen) return;

    function showPopup() {
      if (shown || !popup) return;
      shown = true;
      popup.classList.add("is-open");
      popup.setAttribute("aria-hidden", "false");
      try {
        window.sessionStorage.setItem(cooldownKey, "1");
      } catch (err) {}
    }

    document.addEventListener("mouseleave", function (event) {
      if (event.clientY <= threshold) {
        showPopup();
      }
    });

    var closeButtons = qsa("[data-exit-close]", popup);
    closeButtons.forEach(function (button) {
      button.addEventListener("click", function () {
        popup.classList.remove("is-open");
        popup.setAttribute("aria-hidden", "true");
      });
    });
  }

  function initStickyMobileAddToCart() {
    var bar = qs("[data-sticky-add-to-cart], .sticky-cart-bar");
    if (!bar) return;

    var source = qs("[data-sticky-source], .product-detail");
    var threshold = 220;
    var visible = false;

    function update() {
      if (window.innerWidth > 820) {
        bar.classList.remove("is-visible");
        return;
      }

      var y = window.scrollY || window.pageYOffset;
      var shouldShow = y >= threshold;
      if (source) {
        var sourceBottom = source.getBoundingClientRect().bottom + y;
        shouldShow = y >= threshold && sourceBottom > y;
      }

      bar.classList.toggle("is-visible", shouldShow);
      visible = shouldShow;
    }

    window.addEventListener("scroll", update, { passive: true });
    window.addEventListener("resize", update);
    update();
  }

  function initRevealGates() {
    qsa("[data-reveal-gate]").forEach(function (el) {
      var gate = el.getAttribute("data-reveal-gate");
      var delay = parseInt(el.getAttribute("data-reveal-delay") || "0", 10);
      var className = "is-locked";
      var unlocked = false;

      function unlock() {
        if (unlocked) return;
        unlocked = true;
        el.classList.remove(className);
        el.setAttribute("data-reveal-state", "unlocked");
        var gateButton = qs("[data-reveal-unlock], [data-gate-unlock]", el);
        if (gateButton) {
          gateButton.setAttribute("aria-expanded", "true");
        }
      }

      if (!gate || gate === "none") {
        el.setAttribute("data-reveal-state", "unlocked");
        return;
      }

      el.classList.add(className);
      el.setAttribute("data-reveal-state", "locked");

      if (gate === "immediate") {
        window.setTimeout(unlock, Math.max(delay, 0));
        return;
      }

      var trigger = qs("[data-reveal-unlock], [data-gate-unlock]", el);
      if (trigger) {
        trigger.addEventListener("click", function (event) {
          event.preventDefault();
          unlock();
        });
      }

      if (gate === "hover") {
        el.addEventListener("mouseenter", unlock);
      }

      if (gate === "scroll") {
        var observer = new IntersectionObserver(function (entries) {
          entries.forEach(function (entry) {
            if (entry.isIntersecting) {
              unlock();
              observer.disconnect();
            }
          });
        }, { threshold: 0.4 });
        observer.observe(el);
      }

      if (gate === "timeout") {
        window.setTimeout(unlock, Math.max(delay || 1500, 0));
      }
    });
  }

  function initImageThumbs() {
    qsa("[data-product-gallery], .product-gallery").forEach(function (gallery) {
      qsa("[data-product-thumb], .product-thumb", gallery).forEach(function (thumb) {
        thumb.addEventListener("click", function () {
          var main = qs("[data-product-main], .product-gallery__main img", gallery);
          var src = thumb.getAttribute("data-full-src") || thumb.getAttribute("src");
          if (main && src) {
            if (main.tagName && main.tagName.toLowerCase() === "img") {
              main.src = src;
            } else {
              main.style.backgroundImage = "url('" + src.replace(/'/g, "%27") + "')";
            }
          }
          qsa("[data-product-thumb], .product-thumb", gallery).forEach(function (t) {
            t.classList.remove("is-active");
          });
          thumb.classList.add("is-active");
        });
      });
    });
  }

  function initMobileBottomNav() {
    var bottomNav = qs("[data-mobile-bottom-nav], .mobile-bottom-nav");
    if (!bottomNav) return;

    // Keep current link highlighted based on location
    qsa("a[href]", bottomNav).forEach(function (link) {
      try {
        var url = new URL(link.href, window.location.origin);
        if (url.pathname === window.location.pathname) {
          link.classList.add("is-active");
        }
      } catch (err) {}
    });
  }

  function initForms() {
    qsa("form").forEach(function (form) {
      form.addEventListener("submit", function () {
        form.classList.add("is-submitting");
      });
    });

    qsa("[data-autocomplete-search]").forEach(function (input) {
      input.addEventListener("input", function () {
        var target = getTargetFromControl(input);
        if (target) target.classList.toggle("has-query", !!input.value.trim());
      });
    });
  }

  function initPageState() {
    var gate = qs("[data-page-loaded]");
    if (gate) gate.setAttribute("data-page-loaded", "true");
    root.classList.add("js-ready");
  }

  function init() {
    initPageState();
    initMobileMenu();
    initDropdowns();
    initAccordions();
    initSliders();
    initQuickView();
    initWishlistCartPlaceholders();
    initExitIntent();
    initStickyMobileAddToCart();
    initRevealGates();
    initImageThumbs();
    initMobileBottomNav();
    initForms();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();