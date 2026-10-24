-- WHAT: Gives every existing app an explicit allowed_gateways list of Pesapal only.
-- WHY:  Gateway enforcement now rejects gateways an app is not allowed to use; apps without a list keep their current behavior.

UPDATE hekta_app_credentials
SET allowed_gateways = JSON_ARRAY('pesapal')
WHERE allowed_gateways IS NULL;

-- ROLLBACK:
-- UPDATE hekta_app_credentials SET allowed_gateways = NULL WHERE JSON_EXTRACT(allowed_gateways, '$') = JSON_ARRAY('pesapal');
