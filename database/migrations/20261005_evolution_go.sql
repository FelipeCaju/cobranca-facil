-- Compatibilidade nativa com Evolution GO.
-- A chave global permanece em evolution_master_api_key; cada sessão usa token próprio.
ALTER TABLE master_settings
  ADD COLUMN IF NOT EXISTS master_whatsapp_instance_token TEXT NULL AFTER master_whatsapp_instance_name;

-- companies.whatsapp_token já existe e passa a armazenar, criptografado pela aplicação,
-- o token da instância Evolution GO da empresa. Nenhuma coluna por tenant é necessária.
