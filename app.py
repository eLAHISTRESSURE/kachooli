import os
import secrets
import re
import mysql.connector
import sqlite3
from datetime import datetime, timedelta
from functools import wraps
from dotenv import load_dotenv
from urllib.parse import quote

from flask import Flask, abort, flash, jsonify, redirect, render_template, request, session, url_for

try:
    from werkzeug.security import check_password_hash, generate_password_hash
except Exception:  # pragma: no cover
    check_password_hash = None
    generate_password_hash = None

load_dotenv()

DB_TYPE = "mysql"

def initialize_sqlite_db(conn):
    """Initialize SQLite database schema and seed data."""
    cursor = conn.cursor()
    
    # Create tables
    cursor.execute("""
    CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        full_name TEXT NOT NULL,
        email TEXT NOT NULL UNIQUE,
        phone TEXT,
        password_hash TEXT NOT NULL,
        gender TEXT,
        date_of_birth TEXT,
        is_active INTEGER NOT NULL DEFAULT 1,
        is_verified INTEGER NOT NULL DEFAULT 0,
        last_login_at TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
    """)
    
    cursor.execute("""
    CREATE TABLE IF NOT EXISTS admin_users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER UNIQUE,
        name TEXT NOT NULL,
        email TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT 'admin',
        last_login_at TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
    """)
    
    cursor.execute("""
    CREATE TABLE IF NOT EXISTS categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        parent_id INTEGER,
        name TEXT NOT NULL,
        slug TEXT NOT NULL UNIQUE,
        description TEXT,
        sort_order INTEGER NOT NULL DEFAULT 0,
        is_active INTEGER NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
    """)
    
    cursor.execute("""
    CREATE TABLE IF NOT EXISTS products (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        category_id INTEGER,
        name TEXT NOT NULL,
        slug TEXT NOT NULL UNIQUE,
        sku TEXT UNIQUE,
        short_description TEXT,
        description TEXT,
        price REAL NOT NULL DEFAULT 0.0,
        compare_at_price REAL,
        cost_price REAL,
        stock_quantity INTEGER NOT NULL DEFAULT 0,
        low_stock_threshold INTEGER NOT NULL DEFAULT 5,
        is_featured INTEGER NOT NULL DEFAULT 0,
        is_new INTEGER NOT NULL DEFAULT 0,
        is_bestseller INTEGER NOT NULL DEFAULT 0,
        is_active INTEGER NOT NULL DEFAULT 1,
        material TEXT,
        size_guide TEXT,
        care_instructions TEXT,
        meta_title TEXT,
        meta_description TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
    """)
    
    cursor.execute("""
    CREATE TABLE IF NOT EXISTS product_images (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        image_url TEXT NOT NULL,
        is_primary INTEGER NOT NULL DEFAULT 0,
        sort_order INTEGER NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
    """)
    
    cursor.execute("""
    CREATE TABLE IF NOT EXISTS banners (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        placement TEXT NOT NULL,
        image_url TEXT NOT NULL,
        title TEXT,
        subtitle TEXT,
        button_text TEXT,
        link_url TEXT,
        sort_order INTEGER NOT NULL DEFAULT 0,
        is_active INTEGER NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
    """)
    
    cursor.execute("""
    CREATE TABLE IF NOT EXISTS cart (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        session_id TEXT,
        product_id INTEGER NOT NULL,
        quantity INTEGER NOT NULL DEFAULT 1,
        selected_size TEXT,
        selected_color TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
    """)
    
    cursor.execute("""
    CREATE TABLE IF NOT EXISTS wishlist (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        product_id INTEGER NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
    """)
    
    cursor.execute("""
    CREATE TABLE IF NOT EXISTS orders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        order_number TEXT NOT NULL UNIQUE,
        customer_name TEXT NOT NULL,
        email TEXT NOT NULL,
        phone TEXT NOT NULL,
        shipping_address TEXT NOT NULL,
        subtotal_amount REAL NOT NULL,
        shipping_amount REAL NOT NULL,
        discount_amount REAL NOT NULL DEFAULT 0.0,
        total_amount REAL NOT NULL,
        payment_method TEXT NOT NULL,
        payment_status TEXT NOT NULL DEFAULT 'pending',
        shipping_status TEXT NOT NULL DEFAULT 'pending',
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
    """)
    
    cursor.execute("""
    CREATE TABLE IF NOT EXISTS order_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL,
        product_id INTEGER NOT NULL,
        quantity INTEGER NOT NULL,
        price REAL NOT NULL,
        selected_size TEXT,
        selected_color TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
    """)
    
    cursor.execute("""
    CREATE TABLE IF NOT EXISTS reviews (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        product_id INTEGER NOT NULL,
        order_id INTEGER,
        customer_name TEXT NOT NULL,
        customer_email TEXT,
        rating INTEGER NOT NULL,
        title TEXT,
        comment TEXT NOT NULL,
        is_verified_purchase INTEGER NOT NULL DEFAULT 0,
        is_approved INTEGER NOT NULL DEFAULT 1,
        admin_reply TEXT,
        reviewed_at TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
    """)
    
    cursor.execute("""
    CREATE TABLE IF NOT EXISTS newsletter_subscribers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT NOT NULL UNIQUE,
        full_name TEXT,
        source TEXT DEFAULT 'website',
        is_subscribed INTEGER NOT NULL DEFAULT 1,
        subscribed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        unsubscribed_at TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
    """)
    
    cursor.execute("""
    CREATE TABLE IF NOT EXISTS coupons (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code TEXT NOT NULL UNIQUE,
        discount_type TEXT NOT NULL DEFAULT 'percentage',
        discount_value REAL NOT NULL,
        min_spend REAL,
        max_discount REAL,
        is_active INTEGER NOT NULL DEFAULT 1,
        expires_at TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
    """)
    
    # Seeding
    # Categories
    categories = [
        (1, None, "Signature Lace", "signature-lace", "Exquisite underwear and corsets.", 10, 1),
        (2, None, "Silk & Satin", "silk-satin", "Mulberry silk sleepwear and robes.", 20, 1),
        (3, None, "Everyday Comfort", "everyday-comfort", "Seamless basics for daily wear.", 30, 1),
    ]
    cursor.executemany(
        "INSERT OR IGNORE INTO categories (id, parent_id, name, slug, description, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)",
        categories
    )

    # Products
    products = [
        (1, 1, "Deluxe Crimson Lace Bodysuit", "deluxe-crimson-lace-bodysuit", "SKU-001", 
         "Exquisite corded lace bodysuit with underwire support.", 
         "A breathtaking crimson bodysuit featuring intricate hand-corded floral lace, adjustable satin straps, and a snap-button closure. Offers gentle lifting support and a seamless silhouette under evening wear.", 
         2499.00, 3499.00, 1000.00, 15, "Premium Corded Lace & Mesh", "Wash separately by hand. Air dry.", 
         "Deluxe Crimson Lace Bodysuit", "Crimson lace bodysuit with adjustable straps.", 1),
         
        (2, 1, "Midnight Black Lace Push-Up Bra", "midnight-black-lace-push-up-bra", "SKU-002", 
         "Plunge push-up bra with scalloped lace trim.", 
         "Crafted with signature eyelash lace, this underwired plunge bra delivers beautiful definition and all-day comfort. Styled with dynamic double satin straps and golden hardware adjustments.", 
         1899.00, 2590.00, 750.00, 20, "Scalloped Eyelash Lace & Satin", "Hand wash in cool water.", 
         "Midnight Black Lace Push-Up Bra", "Eyelash lace plunge push-up bra in midnight black.", 1),
         
        (3, 2, "Mulberry Silk Long Robe", "mulberry-silk-long-robe", "SKU-003", 
         "Flowing silk robe with dynamic sash belt.", 
         "Indulge in pure luxury with this floor-length robe made of 100% organic mulberry silk. Designed with elegant draped kimono sleeves, deep side pockets, and an inner tie to secure the fit.", 
         5999.00, 7999.00, 2500.00, 8, "100% Organic Mulberry Silk", "Dry clean or hand wash with silk detergent.", 
         "Mulberry Silk Long Robe", "Luxury mulberry silk floor-length robe.", 1),

        (4, 3, "Seamless Everyday Balconette Bra", "seamless-everyday-balconette-bra", "SKU-004", 
         "Invisible seamless balconette bra for daily wear.", 
         "A modern classic. Featuring smooth, heat-molded cups that contour to your shape, this stitch-free balconette bra is completely invisible under tight clothing.", 
         1499.00, 1999.00, 500.00, 30, "Microfiber Blend (Nylon/Spandex)", "Machine wash cold in delicates bag.", 
         "Seamless Everyday Balconette Bra", "Stitch-free molded balconette bra.", 1),
    ]
    cursor.executemany(
        """
        INSERT OR IGNORE INTO products (id, category_id, name, slug, sku, short_description, description, price, compare_at_price, cost_price, stock_quantity, material, care_instructions, meta_title, meta_description, is_featured) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        """,
        products
    )

    # Product Images
    product_images = [
        (1, "https://images.unsplash.com/photo-1574269909862-7e1d70bb8078?q=80&w=600", 1, 10),
        (1, "https://images.unsplash.com/photo-1613987549117-13c4781b32d3?q=80&w=600", 0, 20),
        (2, "https://images.unsplash.com/photo-1594633312681-425c7b97ccd1?q=80&w=600", 1, 10),
        (3, "https://images.unsplash.com/photo-1544441893-675973e31985?q=80&w=600", 1, 10),
        (4, "https://images.unsplash.com/photo-1562572159-4ebcd318f4dd?q=80&w=600", 1, 10),
    ]
    cursor.executemany(
        "INSERT OR IGNORE INTO product_images (product_id, image_url, is_primary, sort_order) VALUES (?, ?, ?, ?)",
        product_images
    )

    # Banners
    banners = [
        ("home", "https://images.unsplash.com/photo-1469334031218-e382a71b716b?q=80&w=1200", 
         "The Eyelash Lace Edit", "Sculpted details, absolute comfort.", "Shop Collection", 10),
        ("home", "https://images.unsplash.com/photo-1490481651871-ab68de25d43d?q=80&w=1200", 
         "Silk Loungewear", "Elegant mulberry silk sleep sets.", "Explore Silk", 20)
    ]
    cursor.executemany(
        "INSERT OR IGNORE INTO banners (placement, image_url, title, subtitle, button_text, sort_order) VALUES (?, ?, ?, ?, ?, ?)",
        banners
    )

    # Users
    pwd_hash = generate_password_hash("password123")
    cursor.execute(
        "INSERT OR IGNORE INTO users (id, full_name, email, password_hash, is_active, is_verified) VALUES (1, 'Jane Doe', 'jane@kacooli.com', ?, 1, 1)",
        (pwd_hash,)
    )

    # Admin Users
    admin_pwd_hash = generate_password_hash("admin123")
    cursor.execute(
        "INSERT OR IGNORE INTO users (id, full_name, email, password_hash, is_active, is_verified) VALUES (2, 'Admin Manager', 'admin@kacooli.com', ?, 1, 1)",
        (admin_pwd_hash,)
    )
    cursor.execute(
        "INSERT OR IGNORE INTO admin_users (user_id, name, email, password_hash, role) VALUES (2, 'Admin Manager', 'admin@kacooli.com', ?, 'superadmin')",
        (admin_pwd_hash,)
    )

    # Reviews
    reviews = [
        (1, 1, "Nomvula M.", "nomvula@example.com", 5, "Exquisite fit!", "The Deluxe Crimson Bodysuit is gorgeous. Sizing chart was spot on, and packaging was highly discreet.", 1),
        (1, 2, "Leah T.", "leah@example.com", 4, "Extremely comfortable", "Beautiful lace details. Will buy again in Rose Blush.", 1),
    ]
    cursor.executemany(
        "INSERT OR IGNORE INTO reviews (product_id, user_id, customer_name, customer_email, rating, title, comment, is_approved) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
        reviews
    )
    
    # Coupons
    coupons = [
        (1, "EID20", "percentage", 20.0, 1000.0, 500.0, 1, None),
        (2, "WELCOME10", "percentage", 10.0, 0.0, 200.0, 1, None)
    ]
    cursor.executemany(
        "INSERT OR IGNORE INTO coupons (id, code, discount_type, discount_value, min_spend, max_discount, is_active, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
        coupons
    )

    conn.commit()
    print("SQLite database seeded successfully.")

def get_db_connection():
    """Create and return a database connection."""
    global DB_TYPE
    if DB_TYPE == "mysql":
        try:
            conn = mysql.connector.connect(
                host=os.environ.get("DB_HOST", "localhost"),
                user=os.environ.get("DB_USER", "root"),
                password=os.environ.get("DB_PASSWORD", ""),
                database=os.environ.get("DB_NAME", "kacooli_enp360"),
                charset="utf8mb4",
                collation="utf8mb4_unicode_ci",
                autocommit=False
            )
            return conn
        except mysql.connector.Error as err:
            print(f"Database connection error: {err}. Switching to SQLite fallback...")
            DB_TYPE = "sqlite"

    if DB_TYPE == "sqlite":
        db_dir = "database"
        if not os.path.exists(db_dir):
            os.makedirs(db_dir)
        db_path = os.path.join(db_dir, "kacooli.db")
        init_needed = not os.path.exists(db_path)
        
        conn = sqlite3.connect(db_path)
        conn.row_factory = sqlite3.Row
        
        if init_needed:
            initialize_sqlite_db(conn)
        return conn


def create_app() -> Flask:
    app = Flask(__name__, template_folder="templates", static_folder="static")

    app.config.update(
        SECRET_KEY=os.environ.get("FLASK_SECRET_KEY", secrets.token_hex(32)),
        SESSION_COOKIE_HTTPONLY=True,
        SESSION_COOKIE_SAMESITE="Lax",
        SESSION_COOKIE_SECURE=os.environ.get("FLASK_SESSION_SECURE", "0") == "1",
        SESSION_COOKIE_AGE=2592000,  # 30 days
        PERMANENT_SESSION_LIFETIME=timedelta(days=30),
        MAX_CONTENT_LENGTH=8 * 1024 * 1024,
        TEMPLATES_AUTO_RELOAD=True,
        JSON_SORT_KEYS=False,
    )

    app.jinja_env.auto_reload = True

    # ==================== DATABASE QUERIES ====================

    def query_db(query, args=(), one=False):
        """Execute a database query."""
        conn = get_db_connection()
        if not conn:
            return None
        try:
            if DB_TYPE == "sqlite":
                query = query.replace("%s", "?")
                cursor = conn.cursor()
                cursor.execute(query, args)
                rv = cursor.fetchall()
                res = [dict(row) for row in rv]
                cursor.close()
                conn.close()
                return (res[0] if res else None) if one else res
            else:
                cursor = conn.cursor(dictionary=True)
                cursor.execute(query, args)
                rv = cursor.fetchall()
                cursor.close()
                conn.close()
                return (rv[0] if rv else None) if one else rv
        except Exception as err:
            print(f"Database query error: {err}")
            return None

    def execute_db(query, args=(), return_id=False):
        """Execute a database operation (INSERT, UPDATE, DELETE)."""
        conn = get_db_connection()
        if not conn:
            return False if not return_id else None
        try:
            if DB_TYPE == "sqlite":
                query = query.replace("%s", "?")
                cursor = conn.cursor()
                cursor.execute(query, args)
                conn.commit()
                last_id = cursor.lastrowid
                cursor.close()
                conn.close()
                return last_id if return_id else True
            else:
                cursor = conn.cursor()
                cursor.execute(query, args)
                conn.commit()
                last_id = cursor.lastrowid
                cursor.close()
                conn.close()
                return last_id if return_id else True
        except Exception as err:
            print(f"Database execute error: {err}")
            return False if not return_id else None

    def get_user_by_email(email):
        """Get user by email."""
        return query_db("SELECT * FROM users WHERE email = %s", (email.lower(),), one=True)

    def get_user_by_id(user_id):
        """Get user by ID."""
        return query_db("SELECT * FROM users WHERE id = %s", (user_id,), one=True)

    def get_products(limit=12, offset=0, category_id=None):
        """Get products with optional filtering."""
        if category_id:
            query = """
                SELECT p.*, c.name as category_name,
                       (SELECT image_url FROM product_images WHERE product_id = p.id ORDER BY is_primary DESC, sort_order ASC LIMIT 1) AS image_url,
                       (SELECT image_url FROM product_images WHERE product_id = p.id ORDER BY is_primary DESC, sort_order ASC LIMIT 1) AS image
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
                WHERE p.is_active = 1 AND p.category_id = %s
                ORDER BY p.created_at DESC LIMIT %s OFFSET %s
            """
            return query_db(query, (category_id, limit, offset))
        else:
            query = """
                SELECT p.*, c.name as category_name,
                       (SELECT image_url FROM product_images WHERE product_id = p.id ORDER BY is_primary DESC, sort_order ASC LIMIT 1) AS image_url,
                       (SELECT image_url FROM product_images WHERE product_id = p.id ORDER BY is_primary DESC, sort_order ASC LIMIT 1) AS image
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
                WHERE p.is_active = 1
                ORDER BY p.created_at DESC LIMIT %s OFFSET %s
            """
            return query_db(query, (limit, offset))

    def get_product_by_slug(slug):
        """Get product by slug."""
        return query_db(
            """
            SELECT p.*, c.name as category_name,
                   (SELECT image_url FROM product_images WHERE product_id = p.id ORDER BY is_primary DESC, sort_order ASC LIMIT 1) AS image_url,
                   (SELECT image_url FROM product_images WHERE product_id = p.id ORDER BY is_primary DESC, sort_order ASC LIMIT 1) AS image
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            WHERE p.slug = %s AND p.is_active = 1
            """,
            (slug,),
            one=True
        )

    def get_product_by_id(product_id):
        """Get product by ID."""
        return query_db(
            """
            SELECT p.*, c.name as category_name,
                   (SELECT image_url FROM product_images WHERE product_id = p.id ORDER BY is_primary DESC, sort_order ASC LIMIT 1) AS image_url,
                   (SELECT image_url FROM product_images WHERE product_id = p.id ORDER BY is_primary DESC, sort_order ASC LIMIT 1) AS image
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            WHERE p.id = %s AND p.is_active = 1
            """,
            (product_id,),
            one=True
        )

    def get_product_images(product_id):
        """Get images for a product."""
        return query_db("SELECT * FROM product_images WHERE product_id = %s ORDER BY sort_order ASC, is_primary DESC", (product_id,))

    def get_product_videos(product_id):
        """Get videos for a product."""
        return query_db("SELECT * FROM product_videos WHERE product_id = %s ORDER BY sort_order ASC, is_primary DESC", (product_id,))

    def get_categories(parent_id=None):
        """Get categories."""
        if parent_id is None:
            return query_db("SELECT * FROM categories WHERE is_active = 1 AND parent_id IS NULL ORDER BY sort_order ASC")
        return query_db("SELECT * FROM categories WHERE is_active = 1 AND parent_id = %s ORDER BY sort_order ASC", (parent_id,))

    def get_category_by_id(category_id):
        """Get category by ID."""
        return query_db("SELECT * FROM categories WHERE id = %s", (category_id,), one=True)

    def get_banners(placement="home"):
        """Get active banners for a placement."""
        now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        return query_db(
            "SELECT * FROM banners WHERE is_active = 1 AND placement = %s AND (starts_at IS NULL OR starts_at <= %s) AND (ends_at IS NULL OR ends_at >= %s) ORDER BY sort_order ASC",
            (placement, now, now)
        )

    def get_featured_products(limit=8):
        """Get featured products."""
        return query_db(
            """
            SELECT p.*,
                   (SELECT image_url FROM product_images WHERE product_id = p.id ORDER BY is_primary DESC, sort_order ASC LIMIT 1) AS image_url,
                   (SELECT image_url FROM product_images WHERE product_id = p.id ORDER BY is_primary DESC, sort_order ASC LIMIT 1) AS image
            FROM products p
            WHERE p.is_active = 1 AND p.is_featured = 1
            ORDER BY p.created_at DESC LIMIT %s
            """,
            (limit,)
        )

    def get_bestseller_products(limit=8):
        """Get bestseller products."""
        return query_db(
            """
            SELECT p.*,
                   (SELECT image_url FROM product_images WHERE product_id = p.id ORDER BY is_primary DESC, sort_order ASC LIMIT 1) AS image_url,
                   (SELECT image_url FROM product_images WHERE product_id = p.id ORDER BY is_primary DESC, sort_order ASC LIMIT 1) AS image
            FROM products p
            WHERE p.is_active = 1 AND p.is_bestseller = 1
            ORDER BY p.created_at DESC LIMIT %s
            """,
            (limit,)
        )

    def get_new_products(limit=8):
        """Get new arrival products."""
        return query_db(
            """
            SELECT p.*,
                   (SELECT image_url FROM product_images WHERE product_id = p.id ORDER BY is_primary DESC, sort_order ASC LIMIT 1) AS image_url,
                   (SELECT image_url FROM product_images WHERE product_id = p.id ORDER BY is_primary DESC, sort_order ASC LIMIT 1) AS image
            FROM products p
            WHERE p.is_active = 1 AND p.is_new = 1
            ORDER BY p.created_at DESC LIMIT %s
            """,
            (limit,)
        )

    def get_reviews_for_product(product_id, limit=5):
        """Get approved reviews for a product."""
        return query_db(
            "SELECT * FROM reviews WHERE product_id = %s AND is_approved = 1 ORDER BY created_at DESC LIMIT %s",
            (product_id, limit)
        )

    def get_user_wishlist(user_id):
        """Get user's wishlist."""
        return query_db(
            """
            SELECT p.*,
                   (SELECT image_url FROM product_images WHERE product_id = p.id ORDER BY is_primary DESC, sort_order ASC LIMIT 1) AS image_url,
                   (SELECT image_url FROM product_images WHERE product_id = p.id ORDER BY is_primary DESC, sort_order ASC LIMIT 1) AS image
            FROM wishlist w
            JOIN products p ON w.product_id = p.id
            WHERE w.user_id = %s
            ORDER BY w.created_at DESC
            """,
            (user_id,)
        )

    def is_product_in_wishlist(user_id, product_id):
        """Check if product is in user's wishlist."""
        result = query_db("SELECT 1 FROM wishlist WHERE user_id = %s AND product_id = %s", (user_id, product_id), one=True)
        return bool(result)

    # ==================== CONTEXT & HELPERS ====================

    def current_user():
        return session.get("user")

    def is_logged_in() -> bool:
        return bool(current_user())

    def is_admin() -> bool:
        user = current_user()
        return bool(user and user.get("role") == "admin")

    def sanitize_input(text):
        """Basic input sanitization."""
        if not text:
            return ""
        return re.sub(r'[<>\"\'%;()&+]', '', str(text).strip())[:255]

    def validate_email(email):
        """Validate email format."""
        pattern = r'^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$'
        return re.match(pattern, email) is not None

    def login_required(view):
        @wraps(view)
        def wrapped(*args, **kwargs):
            if not is_logged_in():
                flash("Please log in to continue.", "warning")
                return redirect(url_for("login", next=request.path))
            return view(*args, **kwargs)
        return wrapped

    def admin_required(view):
        @wraps(view)
        def wrapped(*args, **kwargs):
            if not is_admin():
                flash("Admin access required. Please log in.", "warning")
                return redirect(url_for("admin_login"))
            return view(*args, **kwargs)
        return wrapped

    @app.context_processor
    def inject_globals():
        categories = get_categories() or []
        return {
            "current_user": current_user(),
            "is_logged_in": is_logged_in(),
            "is_admin": is_admin(),
            "site_name": "Kacooli",
            "categories": categories,
            "year": datetime.utcnow().year,
            "whatsapp_number": os.environ.get("WHATSAPP_NUMBER", "+8801700000000"),
            "currency_symbol": os.environ.get("CURRENCY_SYMBOL", "৳"),
        }


    # ==================== FRONTEND ROUTES ====================

    @app.route("/")
    def home():
        banners = get_banners("home") or []
        featured = get_featured_products(8) or []
        bestsellers = get_bestseller_products(8) or []
        new_products = get_new_products(8) or []
        reviews = query_db("SELECT * FROM reviews WHERE is_approved = 1 ORDER BY created_at DESC LIMIT 6") or []
        return render_template(
            "home.html",
            banners=banners,
            featured_products=featured,
            bestseller_products=bestsellers,
            new_products=new_products,
            reviews=reviews
        )

    @app.route("/shop")
    def shop():
        page = request.args.get("page", 1, type=int)
        category = request.args.get("category", type=int)
        sort = request.args.get("sort", "newest")
        
        per_page = 12
        offset = (page - 1) * per_page
        
        products = get_products(limit=per_page, offset=offset, category_id=category) or []
        categories = get_categories() or []
        
        return render_template(
            "shop.html",
            products=products,
            categories=categories,
            current_page=page,
            current_category=category,
            sort=sort
        )

    @app.route("/product/<slug>")
    def product_detail(slug):
        product = get_product_by_slug(slug)
        if not product:
            abort(404)
        
        images = get_product_images(product["id"]) or []
        videos = get_product_videos(product["id"]) or []
        reviews = get_reviews_for_product(product["id"], 10) or []
        related = query_db("SELECT * FROM products WHERE category_id = %s AND id != %s AND is_active = 1 LIMIT 6", (product["category_id"], product["id"])) or []
        
        in_wishlist = False
        if is_logged_in():
            in_wishlist = is_product_in_wishlist(current_user()["id"], product["id"])
        
        return render_template(
            "product_detail.html",
            product=product,
            images=images,
            videos=videos,
            reviews=reviews,
            related_products=related,
            in_wishlist=in_wishlist
        )

    @app.route("/login", methods=["GET", "POST"])
    def login():
        if is_logged_in():
            return redirect(url_for("dashboard"))
        
        if request.method == "POST":
            email = request.form.get("email", "").strip().lower()
            password = request.form.get("password", "").strip()
            remember = request.form.get("remember") == "on"

            if not email or not password:
                flash("Email and password are required.", "danger")
                return render_template("login.html")

            if not validate_email(email):
                flash("Invalid email format.", "danger")
                return render_template("login.html")

            user = get_user_by_email(email)
            
            if user and check_password_hash(user.get("password_hash", ""), password):
                session["user"] = {
                    "id": user["id"],
                    "name": user["full_name"],
                    "email": user["email"],
                    "role": "customer"
                }
                session.permanent = remember
                
                execute_db("UPDATE users SET last_login_at = NOW() WHERE id = %s", (user["id"],))
                flash("Login successful.", "success")
                
                next_url = request.args.get("next") or url_for("dashboard")
                return redirect(next_url)

            flash("Invalid email or password.", "danger")
            return render_template("login.html")

        return render_template("login.html")

    @app.route("/signup", methods=["GET", "POST"])
    def signup():
        if is_logged_in():
            return redirect(url_for("dashboard"))
        
        if request.method == "POST":
            name = sanitize_input(request.form.get("name", ""))
            email = request.form.get("email", "").strip().lower()
            phone = sanitize_input(request.form.get("phone", ""))
            password = request.form.get("password", "").strip()
            confirm = request.form.get("confirm_password", "").strip()

            if not all([name, email, password, confirm]):
                flash("Please complete all fields.", "danger")
                return render_template("signup.html")

            if not validate_email(email):
                flash("Invalid email format.", "danger")
                return render_template("signup.html")

            if len(password) < 8:
                flash("Password must be at least 8 characters.", "danger")
                return render_template("signup.html")

            if password != confirm:
                flash("Passwords do not match.", "danger")
                return render_template("signup.html")

            if get_user_by_email(email):
                flash("Email already registered.", "danger")
                return render_template("signup.html")

            password_hash = generate_password_hash(password)
            
            if execute_db(
                "INSERT INTO users (full_name, email, phone, password_hash) VALUES (%s, %s, %s, %s)",
                (name, email, phone, password_hash)
            ):
                new_user = get_user_by_email(email)
                if new_user:
                    session["user"] = {
                        "id": new_user["id"],
                        "name": new_user["full_name"],
                        "email": new_user["email"],
                        "role": "customer"
                    }
                    flash("Account created successfully.", "success")
                    return redirect(url_for("dashboard"))

            flash("Error creating account. Please try again.", "danger")
            return render_template("signup.html")

        return render_template("signup.html")

    @app.route("/logout")
    def logout():
        session.clear()
        flash("You have been logged out.", "info")
        return redirect(url_for("home"))

    @app.route("/dashboard")
    @login_required
    def dashboard():
        user_id = current_user()["id"]
        orders = query_db("SELECT * FROM orders WHERE user_id = %s ORDER BY created_at DESC", (user_id,)) or []
        wishlist = get_user_wishlist(user_id) or []
        
        return render_template("dashboard.html", orders=orders, wishlist=wishlist)

    @app.route("/cart")
    def cart():
        if is_logged_in():
            user_id = current_user()["id"]
            cart_items = query_db(
                """
                SELECT c.*, p.name as product_name, p.slug as product_slug, p.price as product_price,
                       (SELECT image_url FROM product_images WHERE product_id = p.id ORDER BY is_primary DESC, sort_order ASC LIMIT 1) as image_url
                FROM cart c
                JOIN products p ON c.product_id = p.id
                WHERE c.user_id = %s
                """,
                (user_id,)
            )
        else:
            session_id = session.get("session_id")
            if not session_id:
                cart_items = []
            else:
                cart_items = query_db(
                    """
                    SELECT c.*, p.name as product_name, p.slug as product_slug, p.price as product_price,
                           (SELECT image_url FROM product_images WHERE product_id = p.id ORDER BY is_primary DESC, sort_order ASC LIMIT 1) as image_url
                    FROM cart c
                    JOIN products p ON c.product_id = p.id
                    WHERE c.session_id = %s
                    """,
                    (session_id,)
                )
        cart_items = cart_items or []
        subtotal = sum(item["line_total"] for item in cart_items)
        shipping_amount = 0.00 if subtotal >= 2000 else 100.00
        total_amount = subtotal + shipping_amount
        return render_template("cart.html", cart_items=cart_items, subtotal=subtotal, shipping_amount=shipping_amount, total_amount=total_amount)

    @app.route("/checkout", methods=["GET", "POST"])
    @login_required
    def checkout():
        user_id = current_user()["id"]
        # Fetch cart items
        cart_items = query_db(
            """
            SELECT c.*, p.name as product_name, p.sku as product_sku, p.price as product_price
            FROM cart c
            JOIN products p ON c.product_id = p.id
            WHERE c.user_id = %s
            """,
            (user_id,)
        )

        if not cart_items:
            flash("Your cart is empty.", "warning")
            return redirect(url_for("cart"))

        # Calculate values
        subtotal = sum(item["line_total"] for item in cart_items)
        shipping_amount = 0.00 if subtotal >= 2000 else 100.00
        tax_amount = 0.00
        discount_amount = 0.00
        coupon_code = session.get("coupon_code")
        
        if coupon_code:
            coupon = query_db("SELECT * FROM coupons WHERE code = %s AND is_active = 1", (coupon_code,), one=True)
            if coupon:
                now = datetime.now()
                starts_at = coupon.get("starts_at")
                ends_at = coupon.get("ends_at")
                valid = True
                if starts_at and now < starts_at:
                    valid = False
                if ends_at and now > ends_at:
                    valid = False
                if valid:
                    if coupon["discount_type"] == "percentage":
                        discount_amount = (subtotal * coupon["discount_value"]) / 100
                        if coupon["maximum_discount_amount"]:
                            discount_amount = min(discount_amount, coupon["maximum_discount_amount"])
                    else:
                        discount_amount = coupon["discount_value"]
                    discount_amount = min(discount_amount, subtotal)

        total_amount = subtotal + shipping_amount + tax_amount - discount_amount

        if request.method == "POST":
            name = sanitize_input(request.form.get("name", ""))
            email = request.form.get("email", "").strip().lower()
            phone = sanitize_input(request.form.get("phone", ""))
            address1 = sanitize_input(request.form.get("address1", ""))
            address2 = sanitize_input(request.form.get("address2", ""))
            city = sanitize_input(request.form.get("city", ""))
            postal = sanitize_input(request.form.get("postal", ""))
            
            if not all([name, email, phone, address1, city, postal]):
                flash("Please complete all required fields.", "danger")
                return render_template("checkout.html", cart_items=cart_items, subtotal=subtotal, shipping_amount=shipping_amount, total_amount=total_amount, discount_amount=discount_amount)

            order_number = f"ORD-{int(datetime.now().timestamp())}"
            
            order_id = execute_db(
                """
                INSERT INTO orders (
                    order_number, user_id, customer_name, customer_email, customer_phone, 
                    billing_address_line1, billing_address_line2, billing_city, billing_postal_code, 
                    payment_method, order_status, subtotal, discount_amount, shipping_amount, 
                    tax_amount, total_amount, coupon_code, placed_at
                ) 
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, NOW())
                """,
                (
                    order_number, user_id, name, email, phone, 
                    address1, address2, city, postal, 
                    "cod", "pending", subtotal, discount_amount, shipping_amount, 
                    tax_amount, total_amount, coupon_code
                ),
                return_id=True
            )
            
            if order_id:
                for item in cart_items:
                    product = get_product_by_id(item["product_id"])
                    compare_price = product.get("compare_at_price") if product else None
                    execute_db(
                        """
                        INSERT INTO order_items (
                            order_id, product_id, product_name, product_sku, 
                            unit_price, compare_at_price, quantity, line_total
                        ) 
                        VALUES (%s, %s, %s, %s, %s, %s, %s, %s)
                        """,
                        (
                            order_id, item["product_id"], item["product_name"], item["product_sku"],
                            item["unit_price"], compare_price, item["quantity"], item["line_total"]
                        )
                    )
                
                # Clear cart
                execute_db("DELETE FROM cart WHERE user_id = %s", (user_id,))
                # Clear coupon
                session.pop("coupon_code", None)
                
                # Save last order info
                session["last_order"] = {
                    "order_number": order_number,
                    "customer_name": name,
                    "total_amount": float(total_amount),
                    "phone": phone
                }
                
                flash(f"Order {order_number} placed successfully!", "success")
                return redirect(url_for("checkout_success"))

            flash("Error placing order. Please try again.", "danger")
            return render_template("checkout.html", cart_items=cart_items, subtotal=subtotal, shipping_amount=shipping_amount, total_amount=total_amount, discount_amount=discount_amount)

        return render_template("checkout.html", cart_items=cart_items, subtotal=subtotal, shipping_amount=shipping_amount, total_amount=total_amount, discount_amount=discount_amount)

    @app.route("/checkout/success")
    @login_required
    def checkout_success():
        order_info = session.pop("last_order", None)
        if not order_info:
            return redirect(url_for("dashboard"))
            
        whatsapp_num = os.environ.get("WHATSAPP_NUMBER", "+8801700000000").replace("+", "").replace(" ", "")
        message = f"Hello Kacooli, I would like to confirm my order *{order_info['order_number']}* for a total of *{order_info['total_amount']} TK*. My name is {order_info['customer_name']} and phone is {order_info['phone']}."
        whatsapp_url = f"https://wa.me/{whatsapp_num}?text={quote(message)}"
        
        return render_template("checkout_success.html", order=order_info, whatsapp_url=whatsapp_url)


    @app.route("/about")
    def about():
        return render_template("about.html")

    @app.route("/contact", methods=["GET", "POST"])
    def contact():
        if request.method == "POST":
            name = sanitize_input(request.form.get("name", ""))
            email = request.form.get("email", "").strip().lower()
            message = sanitize_input(request.form.get("message", ""))

            if not all([name, email, message]):
                flash("Please complete all fields.", "danger")
                return redirect(url_for("contact"))

            if not validate_email(email):
                flash("Invalid email format.", "danger")
                return redirect(url_for("contact"))

            flash("Thank you for contacting us. We will reply shortly.", "success")
            return redirect(url_for("contact"))

        return render_template("contact.html")

    @app.route("/faq")
    def faq():
        return render_template("faq.html")

    @app.route("/privacy-policy")
    def privacy_policy():
        return render_template("privacy_policy.html")

    @app.route("/terms-and-conditions")
    def terms():
        return render_template("terms.html")

    # ==================== ADMIN ROUTES ====================

    @app.route("/admin")
    @admin_required
    def admin_dashboard():
        total_orders = query_db("SELECT COUNT(*) as count FROM orders", one=True)
        total_users = query_db("SELECT COUNT(*) as count FROM users", one=True)
        total_products = query_db("SELECT COUNT(*) as count FROM products", one=True)
        
        return render_template(
            "admin/dashboard.html",
            total_orders=total_orders["count"] if total_orders else 0,
            total_users=total_users["count"] if total_users else 0,
            total_products=total_products["count"] if total_products else 0
        )

    @app.route("/admin/products")
    @admin_required
    def admin_products():
        products = query_db("SELECT * FROM products ORDER BY created_at DESC") or []
        return render_template("admin/products.html", products=products)

    @app.route("/admin/orders")
    @admin_required
    def admin_orders():
        orders = query_db("SELECT * FROM orders ORDER BY created_at DESC") or []
        return render_template("admin/orders.html", orders=orders)

    @app.route("/admin/users")
    @admin_required
    def admin_users():
        users = query_db("SELECT * FROM users ORDER BY created_at DESC") or []
        return render_template("admin/users.html", users=users)

    @app.route("/admin/banners")
    @admin_required
    def admin_banners():
        banners = query_db("SELECT * FROM banners ORDER BY sort_order ASC") or []
        return render_template("admin/banners.html", banners=banners)

    @app.route("/admin/reviews")
    @admin_required
    def admin_reviews():
        reviews = query_db("SELECT * FROM reviews ORDER BY created_at DESC") or []
        return render_template("admin/reviews.html", reviews=reviews)

    @app.route("/admin/coupons")
    @admin_required
    def admin_coupons():
        coupons = query_db("SELECT * FROM coupons ORDER BY created_at DESC") or []
        return render_template("admin/coupons.html", coupons=coupons)

    # ==================== API ENDPOINTS ====================

    @app.route("/api/wishlist/add", methods=["POST"])
    @login_required
    def api_wishlist_add():
        product_id = request.json.get("product_id")
        user_id = current_user()["id"]

        if not product_id or not get_product_by_id(product_id):
            return jsonify({"error": "Invalid product"}), 400

        if execute_db("INSERT IGNORE INTO wishlist (user_id, product_id) VALUES (%s, %s)", (user_id, product_id)):
            return jsonify({"success": True}), 200
        
        return jsonify({"error": "Error adding to wishlist"}), 500

    @app.route("/api/wishlist/remove", methods=["POST"])
    @login_required
    def api_wishlist_remove():
        product_id = request.json.get("product_id")
        user_id = current_user()["id"]

        if execute_db("DELETE FROM wishlist WHERE user_id = %s AND product_id = %s", (user_id, product_id)):
            return jsonify({"success": True}), 200
        
        return jsonify({"error": "Error removing from wishlist"}), 500

    @app.route("/api/cart/add", methods=["POST"])
    def api_cart_add():
        product_id = request.json.get("product_id")
        quantity = request.json.get("quantity", 1)
        size = request.json.get("size", "")
        color = request.json.get("color", "")

        product = get_product_by_id(product_id)
        if not product:
            return jsonify({"error": "Invalid product"}), 400

        try:
            quantity = int(quantity)
        except ValueError:
            quantity = 1

        if is_logged_in():
            user_id = current_user()["id"]
            execute_db(
                "INSERT INTO cart (user_id, product_id, quantity, selected_size, selected_color, unit_price, line_total) VALUES (%s, %s, %s, %s, %s, %s, %s) ON DUPLICATE KEY UPDATE quantity = quantity + %s, line_total = (quantity + %s) * unit_price",
                (user_id, product_id, quantity, size, color, product["price"], product["price"] * quantity, quantity, quantity)
            )
        else:
            session_id = session.get("session_id") or secrets.token_hex(16)
            session["session_id"] = session_id
            existing = query_db(
                "SELECT id, quantity FROM cart WHERE session_id = %s AND product_id = %s AND selected_size = %s AND selected_color = %s",
                (session_id, product_id, size, color),
                one=True
            )
            if existing:
                new_qty = existing["quantity"] + quantity
                execute_db(
                    "UPDATE cart SET quantity = %s, line_total = %s WHERE id = %s",
                    (new_qty, product["price"] * new_qty, existing["id"])
                )
            else:
                execute_db(
                    "INSERT INTO cart (session_id, product_id, quantity, selected_size, selected_color, unit_price, line_total) VALUES (%s, %s, %s, %s, %s, %s, %s)",
                    (session_id, product_id, quantity, size, color, product["price"], product["price"] * quantity)
                )

        return jsonify({"success": True}), 200

    @app.route("/api/cart/count")
    def api_cart_count():
        if is_logged_in():
            user_id = current_user()["id"]
            res = query_db("SELECT SUM(quantity) as count FROM cart WHERE user_id = %s", (user_id,), one=True)
        else:
            session_id = session.get("session_id")
            if not session_id:
                return jsonify({"count": 0}), 200
            res = query_db("SELECT SUM(quantity) as count FROM cart WHERE session_id = %s", (session_id,), one=True)
        count = res["count"] if res and res["count"] else 0
        return jsonify({"count": count}), 200

    @app.route("/api/wishlist/count")
    def api_wishlist_count():
        if is_logged_in():
            user_id = current_user()["id"]
            res = query_db("SELECT COUNT(*) as count FROM wishlist WHERE user_id = %s", (user_id,), one=True)
            count = res["count"] if res and res["count"] else 0
        else:
            count = 0
        return jsonify({"count": count}), 200

    @app.route("/api/newsletter/subscribe", methods=["POST"])
    def api_newsletter_subscribe():
        email = request.json.get("email", "").strip().lower() if request.is_json else request.form.get("email", "").strip().lower()
        if not email or not validate_email(email):
            return jsonify({"error": "Invalid email address"}), 400
        
        existing = query_db("SELECT 1 FROM newsletter_subscribers WHERE email = %s", (email,), one=True)
        if existing:
            return jsonify({"success": True, "message": "Already subscribed"}), 200
            
        if execute_db("INSERT INTO newsletter_subscribers (email, source) VALUES (%s, %s)", (email, "website")):
            return jsonify({"success": True, "message": "Successfully subscribed"}), 200
        return jsonify({"error": "Failed to subscribe"}), 500

    @app.route("/api/cart/get")
    def api_cart_get():
        if is_logged_in():
            user_id = current_user()["id"]
            items = query_db(
                """
                SELECT c.*, p.name as product_name, p.slug as product_slug, p.price as product_price,
                       (SELECT image_url FROM product_images WHERE product_id = p.id ORDER BY is_primary DESC, sort_order ASC LIMIT 1) as image_url
                FROM cart c
                JOIN products p ON c.product_id = p.id
                WHERE c.user_id = %s
                """,
                (user_id,)
            )
        else:
            session_id = session.get("session_id")
            if not session_id:
                return jsonify({"items": [], "subtotal": 0}), 200
            items = query_db(
                """
                SELECT c.*, p.name as product_name, p.slug as product_slug, p.price as product_price,
                       (SELECT image_url FROM product_images WHERE product_id = p.id ORDER BY is_primary DESC, sort_order ASC LIMIT 1) as image_url
                FROM cart c
                JOIN products p ON c.product_id = p.id
                WHERE c.session_id = %s
                """,
                (session_id,)
            )
        
        items = items or []
        subtotal = sum(item["line_total"] for item in items)
        return jsonify({"items": items, "subtotal": float(subtotal)}), 200

    @app.route("/api/cart/remove", methods=["POST"])
    def api_cart_remove():
        cart_id = request.json.get("cart_id")
        if not cart_id:
            return jsonify({"error": "Missing cart ID"}), 400
            
        if is_logged_in():
            user_id = current_user()["id"]
            success = execute_db("DELETE FROM cart WHERE id = %s AND user_id = %s", (cart_id, user_id))
        else:
            session_id = session.get("session_id")
            if not session_id:
                return jsonify({"error": "No active session"}), 400
            success = execute_db("DELETE FROM cart WHERE id = %s AND session_id = %s", (cart_id, session_id))
            
        if success:
            return jsonify({"success": True}), 200
        return jsonify({"error": "Failed to remove item"}), 500

    @app.route("/api/cart/update", methods=["POST"])
    def api_cart_update():
        cart_id = request.json.get("cart_id")
        quantity = request.json.get("quantity")
        if not cart_id or quantity is None or int(quantity) < 1:
            return jsonify({"error": "Invalid request parameters"}), 400
            
        quantity = int(quantity)
        
        if is_logged_in():
            user_id = current_user()["id"]
            item = query_db("SELECT * FROM cart WHERE id = %s AND user_id = %s", (cart_id, user_id), one=True)
        else:
            session_id = session.get("session_id")
            if not session_id:
                return jsonify({"error": "No active session"}), 400
            item = query_db("SELECT * FROM cart WHERE id = %s AND session_id = %s", (cart_id, session_id), one=True)
            
        if not item:
            return jsonify({"error": "Cart item not found"}), 404
            
        line_total = item["unit_price"] * quantity
        if is_logged_in():
            success = execute_db("UPDATE cart SET quantity = %s, line_total = %s WHERE id = %s AND user_id = %s", (quantity, line_total, cart_id, user_id))
        else:
            success = execute_db("UPDATE cart SET quantity = %s, line_total = %s WHERE id = %s AND session_id = %s", (quantity, line_total, cart_id, session_id))
            
        if success:
            return jsonify({"success": True}), 200
        return jsonify({"error": "Failed to update quantity"}), 500

    @app.route("/api/coupon/apply", methods=["POST"])
    def api_coupon_apply():
        code = request.json.get("code", "").strip()
        if not code:
            return jsonify({"error": "Coupon code is required"}), 400
            
        coupon = query_db("SELECT * FROM coupons WHERE code = %s AND is_active = 1", (code,), one=True)
        if not coupon:
            return jsonify({"error": "Invalid coupon code"}), 404
            
        now = datetime.now()
        starts_at = coupon.get("starts_at")
        ends_at = coupon.get("ends_at")
        
        if starts_at and now < starts_at:
            return jsonify({"error": "Coupon code is not active yet"}), 400
        if ends_at and now > ends_at:
            return jsonify({"error": "Coupon code has expired"}), 400
            
        if coupon["usage_limit"] is not None and coupon["used_count"] >= coupon["usage_limit"]:
            return jsonify({"error": "Coupon code usage limit reached"}), 400
            
        session["coupon_code"] = coupon["code"]
        return jsonify({
            "success": True,
            "code": coupon["code"],
            "discount_type": coupon["discount_type"],
            "discount_value": float(coupon["discount_value"]),
            "minimum_order_amount": float(coupon["minimum_order_amount"]) if coupon["minimum_order_amount"] else 0
        }), 200

    @app.route("/api/reviews/submit", methods=["POST"])
    def api_reviews_submit():
        product_id = request.form.get("product_id") or request.json.get("product_id")
        rating = request.form.get("rating") or request.json.get("rating")
        title = sanitize_input(request.form.get("title", "") or request.json.get("title", ""))
        comment = sanitize_input(request.form.get("comment", "") or request.json.get("comment", ""))
        
        if not product_id or not rating:
            return jsonify({"error": "Product ID and rating are required."}), 400
            
        try:
            rating = int(rating)
            if rating < 1 or rating > 5:
                raise ValueError()
        except ValueError:
            return jsonify({"error": "Rating must be an integer between 1 and 5."}), 400
            
        if not comment:
            return jsonify({"error": "Comment is required."}), 400
            
        if is_logged_in():
            user = current_user()
            user_id = user["id"]
            db_user = get_user_by_id(user_id)
            reviewer_name = db_user["full_name"]
            reviewer_email = db_user["email"]
        else:
            reviewer_name = sanitize_input(request.form.get("name", "") or request.json.get("name", ""))
            reviewer_email = sanitize_input(request.form.get("email", "") or request.json.get("email", ""))
            user_id = None
            if not reviewer_name or not reviewer_email:
                return jsonify({"error": "Name and email are required for guest reviews."}), 400
            if not validate_email(reviewer_email):
                return jsonify({"error": "Invalid email address."}), 400
                
        success = execute_db(
            """
            INSERT INTO reviews (user_id, product_id, reviewer_name, reviewer_email, rating, title, comment, is_approved, reviewed_at)
            VALUES (%s, %s, %s, %s, %s, %s, %s, 1, NOW())
            """,
            (user_id, product_id, reviewer_name, reviewer_email, rating, title, comment)
        )
        
        if success:
            return jsonify({"success": True, "message": "Review submitted successfully!"}), 200
        return jsonify({"error": "Failed to submit review. Please try again."}), 500

    @app.route("/admin/login", methods=["GET", "POST"])
    def admin_login():
        if is_admin():
            return redirect(url_for("admin_dashboard"))
            
        if request.method == "POST":
            email = request.form.get("email", "").strip().lower()
            password = request.form.get("password", "").strip()
            
            if not email or not password:
                flash("Email and password are required.", "danger")
                return render_template("admin/login.html")
                
            admin = query_db("SELECT * FROM admin_users WHERE email = %s", (email,), one=True)
            
            if admin and check_password_hash(admin.get("password_hash", ""), password):
                session["user"] = {
                    "id": admin["id"],
                    "name": admin["name"],
                    "email": admin["email"],
                    "role": "admin"
                }
                execute_db("UPDATE admin_users SET last_login_at = NOW() WHERE id = %s", (admin["id"],))
                flash("Admin login successful.", "success")
                return redirect(url_for("admin_dashboard"))
                
            flash("Invalid admin email or password.", "danger")
            return render_template("admin/login.html")
            
        return render_template("admin/login.html")

    @app.route("/api/image-access")
    def api_image_access():
        """Check if image should be blurred."""
        if is_logged_in():
            return jsonify({"blur": False})
        return jsonify({"blur": True})

    @app.route("/health")
    def health():
        return jsonify({
            "status": "ok",
            "service": "kacooli",
            "logged_in": is_logged_in(),
            "admin": is_admin(),
        })

    # ==================== ADMIN ACTION APIS ====================

    @app.route("/api/admin/orders/update-status", methods=["POST"])
    @admin_required
    def api_admin_order_update_status():
        data = request.get_json() or {}
        order_id = data.get("order_id")
        status = data.get("shipping_status")
        payment_status = data.get("payment_status")
        
        if not order_id or not status:
            return jsonify({"success": False, "error": "Missing parameters"}), 400
            
        if payment_status:
            execute_db(
                "UPDATE orders SET shipping_status = %s, payment_status = %s WHERE id = %s",
                (status, payment_status, order_id)
            )
        else:
            execute_db(
                "UPDATE orders SET shipping_status = %s WHERE id = %s",
                (status, order_id)
            )
        return jsonify({"success": True})

    @app.route("/api/admin/reviews/approve", methods=["POST"])
    @admin_required
    def api_admin_review_approve():
        data = request.get_json() or {}
        review_id = data.get("review_id")
        if not review_id:
            return jsonify({"success": False, "error": "Missing review ID"}), 400
            
        execute_db("UPDATE reviews SET is_approved = 1 WHERE id = %s", (review_id,))
        return jsonify({"success": True})

    @app.route("/api/admin/reviews/reject", methods=["POST"])
    @admin_required
    def api_admin_review_reject():
        data = request.get_json() or {}
        review_id = data.get("review_id")
        if not review_id:
            return jsonify({"success": False, "error": "Missing review ID"}), 400
            
        execute_db("UPDATE reviews SET is_approved = 0 WHERE id = %s", (review_id,))
        return jsonify({"success": True})

    # ==================== ERROR HANDLERS ====================

    @app.errorhandler(403)
    def forbidden(_error):
        return render_template("errors/403.html"), 403

    @app.errorhandler(404)
    def not_found(_error):
        return render_template("errors/404.html"), 404

    @app.errorhandler(413)
    def too_large(_error):
        return render_template("errors/413.html"), 413

    return app


app = create_app()

if __name__ == "__main__":
    app.run(host="0.0.0.0", port=int(os.environ.get("PORT", 5000)), debug=os.environ.get("FLASK_DEBUG", "0") == "1")
