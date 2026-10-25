-- «База знань»: відгуки «допомогло / не допомогло» до публічних статей. source: page — відкрито напряму, form — перехід зі підказки на формі звернення.
CREATE TABLE IF NOT EXISTS kb_feedback (
    id INT AUTO_INCREMENT PRIMARY KEY,
    page_id INT NOT NULL,
    helpful TINYINT(1) NOT NULL,
    source VARCHAR(10) NOT NULL DEFAULT 'page',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_kb_feedback_page (page_id),
    FOREIGN KEY (page_id) REFERENCES wiki_pages(id) ON DELETE CASCADE
) ENGINE=InnoDB;
