# Ecommerce domain glossary

Use these conceptual distinctions before designing persistence. They do not mandate classes, tables, exact cardinalities or accepted business defaults.

| Term | Meaning in this CMS's modeling vocabulary | Do not confuse with |
| --- | --- | --- |
| Product | Merchandise represented in the catalog | A category, a chosen option or necessarily one stock record |
| Category | Catalog classification, potentially hierarchical | A visual layout or sellable item |
| Product/category relationship | Explicit classification membership | Duplicating a product for each category |
| Feature | Descriptive definition such as material, capacity or brand | A customer customization input |
| Feature group | Merchant-facing organization of feature definitions | A variation group or option combination |
| Feature value | A typed fact or predefined value within a feature | A price modifier or customer choice by default |
| Feature assignment | A product's association with a feature and its value(s) | The reusable definition of the value itself |
| Feature variant | CS-Cart's term for a predefined feature value | An option variant or sellable product variation |
| Option | A choice/input supplied by a customer for a purchase, such as wrapping or engraving | A descriptive feature, even when both use a select control |
| Option variant | A predefined allowable choice for an option | A product variation with its own merchandise identity |
| Option selection | The actual chosen variant or entered value | The option definition |
| Option combination | A tuple of choices, potentially subject to allowed/forbidden rules | Automatically a SKU, stock record or product variation |
| Product variation | A sellable variation distinguished from related merchandise by selected feature values | An arbitrary customization or generic attributes blob |
| Variation group | Related variations and the axes that distinguish them | Feature group; the grouping representation is undecided |
| Catalog item | A presentation entry in a product listing | Necessarily every sellable variation |
| Inventory | Authoritative stock facts and operations for a defined tracked identity | Visibility status or a cached availability badge |
| Price | A monetary amount with currency and applicability rules | List/reference price, option modifier or an untyped floating-point number |

## Reason through examples

- Material = Cotton describes a T-shirt: feature definition plus assignment.
- Gift wrapping = Premium expresses a customer-selected option variant. Engraving = “Nikos” illustrates an entered option value, not a predefined variant.
- Color = Blue and Size = Medium can distinguish an existing sellable variation. Selecting it chooses an identity; the UI control does not determine the domain concept.
- A tuple of wrapping and engraving choices does not automatically create inventory. If combinations need SKU or stock ownership, resolve that deliberately with variation modeling.

Examples explain vocabulary only. Color and size are not universally features or options in every commerce model. Choose based on whether the choice selects merchandise identity, describes it or customizes a purchase. Keep those meanings explicit even if some data definitions can be reused.

Before implementation, ask: what is sold, what is tracked, what determines price, what is shown in listings, and what is copied or inherited? Keep product identity, variation identity and catalog presentation distinct until the decision register resolves their representation.

