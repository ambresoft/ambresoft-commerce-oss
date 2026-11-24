---
name: ecommerce-domain
description: Model or review this CMS's ecommerce semantics and product/category/feature/option/combination/variation/inventory/price relationships. Use when business rules or catalog terminology change, including conceptual comparisons with CS-Cart; do not treat the research as a schema specification.
---

# Ecommerce domain

Read [domain-model.md](../../../docs/architecture/domain-model.md) and [glossary.md](references/glossary.md) before introducing or changing catalog concepts.

- Distinguish descriptive features from customer-entered or selected options. A variation selector may look like an option while selecting a different sellable identity.
- Distinguish a reusable feature value from its assignment to a product, an option variant from a chosen option, and a combination rule from a stock-bearing item.
- Keep inventory and price tied to an explicitly chosen sellable identity. Do not decide variation storage, inheritance, SKU scope or stock ownership by accident.
- Describe invariants and observable behavior before proposing tables. Test whether single edits, bulk changes, cloning and imports should invoke the same business rules.
- Preserve the user's product scope. CS-Cart editions, marketplace concepts and addon packaging do not establish requirements for this CMS.
- Label CS-Cart facts, historical facts, our proposals and accepted decisions separately. Never import a source schema or proprietary code.

Read only the relevant comparison:

- [cs-cart-catalog.md](references/cs-cart-catalog.md): products, categories, relationships, statuses, images and filters.
- [cs-cart-features-options.md](references/cs-cart-features-options.md): features, groups, values, options, variants, combinations and variations, including removed legacy behavior.
- [cs-cart-operations.md](references/cs-cart-operations.md): inventory, prices, bulk editing, cloning, import/export and SEO.
- [cs-cart-extensions-themes.md](references/cs-cart-extensions-themes.md): addons, PHP/template hooks and storefront themes.

Consult [decisions.md](../../../docs/architecture/decisions.md) for outstanding choices. Recheck authoritative documentation when current upstream behavior matters; the research records its date and limitations.
