# Example add-on: Bank transfer

A minimal third-party add-on for the CMS add-on contract 2. It contributes one offline payment method and nothing
else. See [the developer guide](../../../addons.md) for the full contract.

```sh
# Check it the way the administration installer does (no installation, no code execution):
docker compose exec -T app php artisan cms:addon-validate docs/examples/addons/acme-bank-transfer

# Package it for Add-ons → Upload (composer.json at the archive root):
cd docs/examples/addons/acme-bank-transfer && zip -r ../acme-bank-transfer-1.0.0.zip . -x '.*'
```
