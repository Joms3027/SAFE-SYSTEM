-- Change supporting_document to supporting_documents (TEXT to store JSON array of file paths)
ALTER TABLE pardon_requests 
CHANGE COLUMN supporting_document supporting_documents TEXT NULL;

