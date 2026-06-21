-- Shopping List Sync — MySQL schema
-- Run once: mysql -u root -p < schema.sql

CREATE DATABASE IF NOT EXISTS shopping
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE shopping;

CREATE TABLE IF NOT EXISTS products (
    id      INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    name    VARCHAR(255)    NOT NULL,
    unit    VARCHAR(64)     NOT NULL,
    store   VARCHAR(255)    NOT NULL,
    updated BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_products_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS shopping_list (
    id         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    product_id INT UNSIGNED    NOT NULL,
    quantity   DECIMAL(10,3)   NOT NULL DEFAULT 1,
    checked    TINYINT(1)      NOT NULL DEFAULT 0,
    in_store   TINYINT(1)      NOT NULL DEFAULT 0,
    updated    BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    CONSTRAINT fk_shopping_product
        FOREIGN KEY (product_id) REFERENCES products (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
