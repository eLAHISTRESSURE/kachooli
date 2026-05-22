# Kacooli E-Commerce — Completion Plan

## Current State Assessment

The project has a solid **foundation** but is missing critical production-ready content:

### ✅ Already Done
- `app.py` — Full Flask backend with all routes, auth, DB queries, security
- `database/schema.sql` — Complete MySQL schema (all 14 tables)
- `static/css/main.css` — Full design system CSS (1450 lines, luxury tokens)
- `static/js/main.js` — Full JS (863 lines with sliders, modals, cart, wishlist, gallery, exit intent)
- `templates/base.html` — Header, footer, nav, WhatsApp float, burger menu
- All admin templates (dashboard, products, orders, users, banners, reviews, coupons)
- All route stubs in place

### ❌ What's Missing / Incomplete

#### Critical Gaps
1. **`app.py`** — Duplicated `app = create_app()` block at end (lines 605-614), missing `/api/cart/count`, `/api/wishlist/count` endpoints, missing newsletter subscribe endpoint, admin login separate route
2. **`base.html`** — HTML/CSS class mismatches (uses old `.header` class but CSS has `.site-header`), missing logo image (only emoji), missing announcement bar CSS
3. **`home.html`** — Uses sample_* variables that don't exist in context, missing full hero slider, Eid sale section, flash sale, video section, Instagram gallery, WhatsApp CTA, social proof counters, exit intent popup markup
4. **`shop.html`** — Grid CSS mismatch (uses `.grid.grid-2` which makes sidebar+products 2-col but CSS collapses both), mobile filter drawer missing
5. **`product_detail.html`** — Uses sample_products (wrong context), missing image gallery, size selector, size guide modal, reviews section, sticky mobile add-to-cart bar
6. **`login.html`** / **`signup.html`** — Uses undefined `.button` class (should be `.btn`), basic form layout
7. **`dashboard.html`** — Very basic, missing orders table, wishlist grid, profile settings
8. **`cart.html`** — Uses `sample_products` instead of real cart data, no quantity steppers, no remove buttons
9. **`checkout.html`** — Missing proper city/postal fields, WhatsApp redirect after order
10. **`about.html`**, **`contact.html`**, **`privacy_policy.html`**, **`terms.html`** — Near-empty stubs
11. **`faq.html`** — Missing accordion JS trigger attribute wrapper
12. **Error templates** — `/templates/errors/` folder empty (404, 403, 413 missing)
13. **Missing CSS** — Many CSS classes referenced in templates don't exist: `.header`, `.announcement-bar`, `.auth-layout`, `.auth-card`, `.product-grid`, `.category-grid`, `.eyebrow`, `.page-hero`, `.cart-layout`, `.checkout-layout`, `.dashboard-grid`, etc.

## Proposed Changes

### Component 1 — Backend Fixes

#### [MODIFY] app.py
- Remove duplicate `create_app()` / `app.run()` block (lines 610–614)
- Add `/api/cart/count` endpoint
- Add `/api/wishlist/count` endpoint
- Add `/api/newsletter/subscribe` endpoint
- Fix admin login: separate `/admin/login` route with admin_users table lookup
- Add cart API: `/api/cart/remove` and `/api/cart/get`
- Add review submission endpoint
- Fix checkout to save cart items to order_items table

---

### Component 2 — CSS Completion

#### [MODIFY] static/css/main.css
Append missing CSS for:
- `.announcement-bar` (top strip)
- `.header` alias / `.site-header` alignment for base.html
- `.eyebrow` / `.section-eyebrow` (kicker text variant)
- `.page-hero` (inner page hero sections)
- `.auth-layout`, `.auth-card`, `.auth-copy`, `.auth-alt`, `.auth-note`
- `.product-grid` (responsive 2/3/4-col product grid)
- `.category-grid` (responsive category tiles)
- `.hero-actions`, `.trust-points`, `.hero-showcase`, `.banner-card`
- `.cart-layout`, `.cart-item`, `.cart-item__media`, `.cart-item__body`
- `.checkout-layout`, `.checkout-form`, `.checkout-message`
- `.summary-card`, `.summary-list`, `.summary-note`, `.summary-lines`, `.summary-line`
- `.dashboard-grid`, `.info-panel`, `.info-panel--dark`, `.feature-list`
- `.form-stack`, `.checkbox-row`
- `.button`, `.button--solid`, `.button--outline`, `.button--small` (aliases for `.btn`)
- `.section-heading`, `.section-eyebrow`
- Admin-specific: `.admin-shell`, `.admin-hero`, `.admin-subnav`, `.stats-grid`, `.stat-card`, `.stat-label`, `.stat-value`, `.stat-note`, `.admin-grid`, `.panel`, `.panel-header`, `.mini-metrics`, `.mini-label`, `.action-list`, `.action-card`, `.badge`, `.badge-secure`, `.badge-warning`, `.badge-danger`, `.badge-neutral`, `.table-toolbar`, `.field`, `.field-search`, `.text-link`, `.admin-trust`, `.admin-meta`
- `.wishlist-trigger.is-active` state
- Mobile drawer for shop filters
- `.exit-popup` overlay
- `.social-counter`, `.flash-sale-banner`, `.eid-countdown`

---

### Component 3 — base.html Complete Rebuild

#### [MODIFY] templates/base.html
- Fix class references (`.site-header` vs `.header`)
- Add actual logo image tag with fallback text
- Add Google Fonts import (Inter + Cormorant Garamond)
- Add announcement bar proper HTML
- Add exit intent popup markup
- Add mobile bottom nav properly
- Fix WhatsApp number to be environment-configurable
- Add CSRF meta tag for future AJAX
- Add schema.org JSON-LD for SEO

---

### Component 4 — Home Page (Full Luxury Build)

#### [MODIFY] templates/home.html
- Fix template variables (`featured_products`, `bestseller_products`, `new_products`, `reviews`, `banners` from Flask context — not `sample_*`)
- Full hero slider with auto-advance
- Eid special countdown section
- Flash sale banner
- New arrivals grid (4-col responsive)
- Best sellers carousel
- Category tiles grid
- Video showcase section
- Instagram-style gallery
- Social proof counters
- Customer reviews slider
- COD trust section
- WhatsApp order CTA
- Email subscription inline
- Brand story preview

---

### Component 5 — Shop Page Enhancement

#### [MODIFY] templates/shop.html
- Fix grid layout (sidebar + grid proper CSS)
- Mobile filter bottom drawer
- Product cards with wishlist toggle
- Quick view trigger buttons
- Scarcity labels ("Only 3 left!")
- Pagination with proper prev/next logic
- Grid vs list view toggle

---

### Component 6 — Product Detail Page (Full Build)

#### [MODIFY] templates/product_detail.html
- Fix to use `product` variable (not `sample_products`)
- Full image gallery with thumbnails
- Size selector with size guide modal
- Add to cart with quantity stepper
- Wishlist button (login-gated)
- Product description, fabric, care instructions tabs
- Reviews section with star ratings
- Related products carousel
- Sticky mobile add-to-cart bar
- COD delivery notice
- WhatsApp inquiry button

---

### Component 7 — Auth Pages (Premium Design)

#### [MODIFY] templates/login.html
- Fix class names (`.btn` not `.button`)  
- Add luxury split layout with brand imagery
- Form validation feedback
- Smooth animations

#### [MODIFY] templates/signup.html
- Complete the form with all fields
- Password strength indicator
- Terms agreement checkbox
- Premium design matching login

---

### Component 8 — Dashboard (Full Build)

#### [MODIFY] templates/dashboard.html
- Real orders table from DB
- Wishlist products grid
- Profile info panel
- Quick links sidebar
- Address management section

---

### Component 9 — Cart Page (Real Cart)

#### [MODIFY] templates/cart.html
- Real cart items from DB (not sample_products)
- Quantity steppers
- Remove item buttons
- Cart total calculation
- Coupon code field
- COD notice
- Empty cart state

---

### Component 10 — Checkout Page Enhancement

#### [MODIFY] templates/checkout.html
- Full address form (line1, line2, city, postal)
- Order summary from cart
- WhatsApp redirect link after order placement
- COD trust badges

---

### Component 11 — Content Pages (Full Content)

#### [MODIFY] templates/about.html
- Brand story with timeline
- Values section
- Team/founder note

#### [MODIFY] templates/contact.html
- Contact form
- WhatsApp CTA button
- Google Maps placeholder
- Social links

#### [MODIFY] templates/faq.html
- Full FAQ accordion (10+ questions)
- Fix accordion wrapper with `data-accordion` attr

#### [MODIFY] templates/privacy_policy.html
- Full privacy policy content

#### [MODIFY] templates/terms.html
- Full terms & conditions content

---

### Component 12 — Error Pages

#### [NEW] templates/errors/404.html
#### [NEW] templates/errors/403.html
#### [NEW] templates/errors/413.html

---

### Component 13 — Admin Login

#### [NEW] templates/admin/login.html
- Separate secure admin login page

#### [MODIFY] app.py
- Add `/admin/login` route with admin_users table lookup

---

## Verification Plan

### After Implementation
1. Run `python app.py` and verify server starts
2. Check home page loads with no missing CSS class errors
3. Verify shop page layout on mobile vs desktop
4. Test login/signup flow end to end
5. Test cart add/remove flow
6. Verify admin panel access control

### Open Questions
- **WhatsApp number**: Currently hardcoded as `+8801700000000`. Should this be in `.env`?
- **Currency symbol**: Currently using `₹` (Indian Rupee). Should it be `৳` (Bangladeshi Taka)?
- **Logo**: The `Kachooli.png` exists in root — should it be moved to `static/images/`?
