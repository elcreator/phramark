CREATE TABLE IF NOT EXISTS phramark_article (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  category INT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  introtext TEXT NOT NULL,
  alias VARCHAR(64) NOT NULL,
  hero_image VARCHAR(255) NOT NULL,
  author VARCHAR(64) NULL,
  reading_time INT UNSIGNED NULL,
  published_at INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  KEY category_published (category, published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
