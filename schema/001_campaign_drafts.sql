-- Fresh campaign storage baseline only; does NOT alter an existing table.
-- Before using an existing database, compare SHOW CREATE TABLE ad_campaigns.
-- Do not replay CREATE TABLE as proof of compatibility or drop existing data.
CREATE TABLE IF NOT EXISTS ad_campaigns (
    id INT NOT NULL AUTO_INCREMENT,
    advertiser_id INT NOT NULL,
    name VARCHAR(160) NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'draft',
    target_radius_km INT NOT NULL,
    media_type VARCHAR(24) NOT NULL,
    headline VARCHAR(90) NOT NULL DEFAULT '',
    message VARCHAR(500) NOT NULL DEFAULT '',
    destination_url VARCHAR(2048) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY campaigns_advertiser_created (advertiser_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
