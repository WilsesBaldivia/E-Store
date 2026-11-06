<?php
declare(strict_types=1);

function db(): PDO
{
    static $connection = null;
    if ($connection instanceof PDO) {
        return $connection;
    }

    // Prefer the application variables used in Railway. The MYSQL* fallbacks
    // also let the app connect when Railway's MySQL variables are attached
    // directly to this service.
    $host = getenv('DB_HOST') ?: (getenv('MYSQLHOST') ?: '127.0.0.1');
    $port = getenv('DB_PORT') ?: (getenv('MYSQLPORT') ?: '3306');
    $name = getenv('DB_NAME') ?: (getenv('MYSQLDATABASE') ?: 'e_storedb');
    $user = getenv('DB_USER') ?: (getenv('MYSQLUSER') ?: 'root');
    $password = getenv('DB_PASSWORD');
    if ($password === false) {
        $password = getenv('MYSQLPASSWORD');
    }
    $password = $password === false ? '' : $password;

    try {
        $connection = new PDO(
            "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
            $user,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 10,
            ]
        );
    } catch (PDOException $exception) {
        error_log('CSCQC database connection failed: ' . $exception->getMessage());
        throw new RuntimeException('The database is temporarily unavailable.');
    }

    return $connection;
}

function ensure_product_media_table(): void
{
    static $ready = false;
    if ($ready) return;

    db()->exec(
        "CREATE TABLE IF NOT EXISTS product_media (
            product_id int unsigned NOT NULL,
            mime_type varchar(50) NOT NULL,
            image_data longblob NOT NULL,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (product_id),
            CONSTRAINT fk_product_media_product
              FOREIGN KEY (product_id) REFERENCES products (id)
              ON UPDATE CASCADE ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $ready = true;
}

function ensure_user_activity_tracking(): void
{
    static $ready = false;
    if ($ready) return;

    $pdo = db();
    $column = $pdo->query("SHOW COLUMNS FROM users LIKE 'last_login_at'")->fetch();
    $addedColumn = false;
    if (!$column) {
        try {
            $pdo->exec('ALTER TABLE users ADD COLUMN last_login_at timestamp NULL DEFAULT NULL AFTER status');
            $addedColumn = true;
        } catch (PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1060) throw $exception;
        }
    }
    $index = $pdo->query("SHOW INDEX FROM users WHERE Key_name = 'idx_users_last_login'")->fetch();
    if (!$index) {
        try {
            $pdo->exec('ALTER TABLE users ADD KEY idx_users_last_login (role, last_login_at)');
        } catch (PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1061) throw $exception;
        }
    }
    if ($addedColumn) {
        // Existing accounts get a fresh six-month window when tracking is introduced.
        $pdo->exec("UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE role = 'student'");
    }
    $ready = true;
}

function ensure_student_catalog_choices(): void
{
    static $ready = false;
    if ($ready) return;

    $pdo = db();
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS app_migrations (
            migration_key varchar(100) NOT NULL,
            applied_at timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (migration_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $migrationKey = 'student_catalog_gender_and_pe_levels_v1';
    $check = $pdo->prepare('SELECT 1 FROM app_migrations WHERE migration_key = :migration_key');
    $check->execute(['migration_key' => $migrationKey]);
    if ($check->fetchColumn()) {
        $ready = true;
        return;
    }

    $products = [
        ['pe-uniforms', 'PE-KINDER-SET', 'Kinder P.E. Uniform', 'Official Kinder physical education uniform set.', 'elementary', 620.00, 'assets/images/products/pe-college-all-departments.png'],
        ['pe-uniforms', 'PE-JHS-SET', 'Junior High School P.E. Uniform', 'Official Junior High School physical education uniform set.', 'jhs', 620.00, 'assets/images/products/pe-college-all-departments.png'],
        ['pe-uniforms', 'PE-SHS-SET', 'Senior High School P.E. Uniform', 'Official Senior High School physical education uniform set.', 'shs', 620.00, 'assets/images/products/pe-college-all-departments.png'],
        ['uniforms', 'UNI-ELEM-GIRLS', 'Elementary School Uniform - Girls', 'Official Elementary school uniform for girls.', 'elementary', 620.00, 'assets/images/products/uniform-elementary.jpg'],
        ['uniforms', 'UNI-ELEM-BOYS', 'Elementary School Uniform - Boys', 'Official Elementary school uniform for boys.', 'elementary', 620.00, 'assets/images/products/uniform-elementary.jpg'],
        ['uniforms', 'UNI-JHS-GIRLS', 'Junior High School Uniform - Girls', 'Official Junior High School uniform for girls.', 'jhs', 650.00, 'assets/images/products/uniform-jhs-clean.png'],
        ['uniforms', 'UNI-JHS-BOYS', 'Junior High School Uniform - Boys', 'Official Junior High School uniform for boys.', 'jhs', 650.00, 'assets/images/products/uniform-jhs-clean.png'],
        ['uniforms', 'UNI-SHS-GIRLS', 'Senior High School Uniform - Girls', 'Official Senior High School uniform for girls.', 'shs', 1200.00, 'assets/images/products/uniform-shs-clean.png'],
        ['uniforms', 'UNI-SHS-BOYS', 'Senior High School Uniform - Boys', 'Official Senior High School uniform for boys.', 'shs', 1200.00, 'assets/images/products/uniform-shs-clean.png'],
    ];

    try {
        $pdo->beginTransaction();
        $pdo->exec(
            "INSERT INTO categories (name, slug, description, is_active) VALUES
                ('Uniforms', 'uniforms', 'Official CSCQC school uniforms', 1),
                ('P.E. Uniforms', 'pe-uniforms', 'Official physical education uniforms', 1)
             ON DUPLICATE KEY UPDATE is_active = 1"
        );
        $insertProduct = $pdo->prepare(
            "INSERT INTO products (category_id, sku, name, description, academic_level, base_price, image_path, is_active)
             VALUES ((SELECT id FROM categories WHERE slug = :category_slug LIMIT 1), :sku, :name, :description, :academic_level, :base_price, :image_path, 1)
             ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), academic_level = VALUES(academic_level),
                 base_price = VALUES(base_price), image_path = COALESCE(image_path, VALUES(image_path)), is_active = 1"
        );
        foreach ($products as [$categorySlug, $sku, $name, $description, $level, $price, $imagePath]) {
            $insertProduct->execute([
                'category_slug' => $categorySlug,
                'sku' => $sku,
                'name' => $name,
                'description' => $description,
                'academic_level' => $level,
                'base_price' => $price,
                'image_path' => $imagePath,
            ]);
        }

        $pdo->exec("UPDATE products SET is_active = 0 WHERE sku IN ('UNI-ELEM-REG', 'UNI-JHS-REG', 'UNI-SHS-REG', 'PE-ELEM-SET')");

        $insertVariant = $pdo->prepare(
            "INSERT INTO product_variants (product_id, variant_sku, size, color, price_override, is_active)
             SELECT id, :variant_sku, :size, NULL, NULL, 1 FROM products WHERE sku = :product_sku
             ON DUPLICATE KEY UPDATE size = VALUES(size), is_active = 1"
        );
        $insertInventory = $pdo->prepare(
            "INSERT INTO inventory (variant_id, stock_quantity, reserved_quantity, reorder_level)
             SELECT id, 20, 0, 5 FROM product_variants WHERE variant_sku = :variant_sku
             ON DUPLICATE KEY UPDATE reorder_level = VALUES(reorder_level)"
        );
        foreach ($products as [, $sku]) {
            foreach (['S' => 'Small', 'M' => 'Medium', 'L' => 'Large', 'XL' => 'XL'] as $sizeCode => $size) {
                $variantSku = $sku . '-' . $sizeCode;
                $insertVariant->execute(['variant_sku' => $variantSku, 'size' => $size, 'product_sku' => $sku]);
                $insertInventory->execute(['variant_sku' => $variantSku]);
            }
        }

        $record = $pdo->prepare('INSERT INTO app_migrations (migration_key) VALUES (:migration_key)');
        $record->execute(['migration_key' => $migrationKey]);
        $pdo->commit();
        $ready = true;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('CSCQC catalog choice migration failed: ' . $exception->getMessage());
    }
}
