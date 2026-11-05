-- Пошук усередині файлів бібліотеки: текст, витягнутий із версії файлу, і FULLTEXT-індекс по ньому.
-- content_indexed = 0 — текст ще не витягувався (його заповнює bin/index-library.php, update.sh запускає його сам).
ALTER TABLE library_versions
    ADD COLUMN content_text MEDIUMTEXT NULL AFTER comment,
    ADD COLUMN content_indexed TINYINT(1) NOT NULL DEFAULT 0 AFTER content_text,
    ADD FULLTEXT KEY ft_content (content_text);
