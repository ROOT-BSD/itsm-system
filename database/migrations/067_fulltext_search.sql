-- Повнотекстові індекси для глобального пошуку (ранжування за релевантністю). Таблиця tasks уже має ft_title_description.
ALTER TABLE projects ADD FULLTEXT KEY ft_name_description (name, description);
ALTER TABLE comments ADD FULLTEXT KEY ft_body (body);
ALTER TABLE tickets ADD FULLTEXT KEY ft_subject_description (subject, description);
ALTER TABLE ticket_comments ADD FULLTEXT KEY ft_body (body);
ALTER TABLE library_documents ADD FULLTEXT KEY ft_title_description (title, description);
ALTER TABLE forum_topics ADD FULLTEXT KEY ft_title (title);
ALTER TABLE forum_posts ADD FULLTEXT KEY ft_body (body);
