import mysql.connector
import os
from dotenv import load_dotenv
from werkzeug.security import generate_password_hash

load_dotenv()

# Connect to mysql server
try:
    conn = mysql.connector.connect(
        host=os.environ.get("DB_HOST", "127.0.0.1"),
        user=os.environ.get("DB_USER", "root"),
        password=os.environ.get("DB_PASSWORD", "")
    )
    cursor = conn.cursor()
    cursor.execute("CREATE DATABASE IF NOT EXISTS kacooli_enp360 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;")
    cursor.execute("USE kacooli_enp360;")
    print("Database created or verified successfully!")
except Exception as e:
    print(f"Error connecting to MySQL: {e}")
    print("Please make sure MySQL is running on localhost (127.0.0.1) port 3306.")
    exit(1)

# Read schema.sql
with open("database/schema.sql", "r", encoding="utf-8") as f:
    schema_sql = f.read()

# Split and clean queries
statements = []
current_stmt = []
for line in schema_sql.splitlines():
    if line.strip().startswith("--") or line.strip().startswith("#") or not line.strip():
        continue
    current_stmt.append(line)
    if line.strip().endswith(";"):
        statements.append("\n".join(current_stmt))
        current_stmt = []

for stmt in statements:
    if stmt.strip():
        try:
            cursor.execute(stmt)
        except Exception as e:
            print(f"Error executing statement: {stmt[:100]}...\nError: {e}")

# Check and Seed
cursor.execute("SELECT COUNT(*) FROM categories")
if cursor.fetchone()[0] == 0:
    print("Seeding database with premium luxury inventory and assets...")
    
    # Categories
    categories = [
        (1, None, "Signature Lace", "signature-lace", "Exquisite corded lace underwear and corsets.", 10, 1),
        (2, None, "Silk & Satin", "silk-satin", "Pure mulberry silk sleepwear and robes.", 20, 1),
        (3, None, "Everyday Comfort", "everyday-comfort", "Soft seamless essentials for daily support.", 30, 1),
    ]
    cursor.executemany(
        "INSERT INTO categories (id, parent_id, name, slug, description, sort_order, is_active) VALUES (%s, %s, %s, %s, %s, %s, %s)",
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
        INSERT INTO products (id, category_id, name, slug, sku, short_description, description, price, compare_at_price, cost_price, stock_quantity, material, care_instructions, meta_title, meta_description, is_featured) 
        VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
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
        "INSERT INTO product_images (product_id, image_url, is_primary, sort_order) VALUES (%s, %s, %s, %s)",
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
        "INSERT INTO banners (placement, image_url, title, subtitle, button_text, sort_order) VALUES (%s, %s, %s, %s, %s, %s)",
        banners
    )

    # Users
    pwd_hash = generate_password_hash("password123")
    cursor.execute(
        "INSERT INTO users (id, full_name, email, password_hash, is_active, is_verified) VALUES (1, 'Jane Doe', 'jane@kacooli.com', %s, 1, 1)",
        (pwd_hash,)
    )

    # Admin Users
    admin_pwd_hash = generate_password_hash("admin123")
    # First create a user row for the admin
    cursor.execute(
        "INSERT INTO users (id, full_name, email, password_hash, is_active, is_verified) VALUES (2, 'Admin Manager', 'admin@kacooli.com', %s, 1, 1)",
        (admin_pwd_hash,)
    )
    cursor.execute(
        "INSERT INTO admin_users (user_id, name, email, password_hash, role) VALUES (2, 'Admin Manager', 'admin@kacooli.com', %s, 'superadmin')",
        (admin_pwd_hash,)
    )

    # Reviews
    reviews = [
        (1, 1, "Nomvula M.", "nomvula@example.com", 5, "Exquisite fit!", "The Deluxe Crimson Bodysuit is gorgeous. Sizing chart was spot on, and packaging was highly discreet.", 1),
        (1, 2, "Leah T.", "leah@example.com", 4, "Extremely comfortable", "Beautiful lace details. Will buy again in Rose Blush.", 1),
    ]
    cursor.executemany(
        "INSERT INTO reviews (product_id, user_id, customer_name, customer_email, rating, title, comment, is_approved) VALUES (%s, %s, %s, %s, %s, %s, %s, %s)",
        reviews
    )

    conn.commit()
    print("Database seeding completed successfully!")
else:
    print("Database already contains data, skipping seed.")

cursor.close()
conn.close()
