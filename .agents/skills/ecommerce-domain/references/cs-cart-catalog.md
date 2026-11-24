# CS-Cart comparison: catalog

Researched 2026-10-08 using authoritative public `/latest/` documentation, generally labeled 4.21.x. These are conceptual summaries, not source/schema copies. Equivalent and disposition paragraphs are our design proposals unless the project brief already fixes them. “Keep” preserves a benefit; “modernize” changes its realization; “reject” identifies behavior/implementation we should not carry over. Recheck upstream when current behavior is material.

## Products

- CS-Cart concept/problem: the merchant's central catalog record for merchandise.
- Behavior: product editing organizes information into tabs, including name, description, price, categories and optional addon data. [Product properties](https://docs.cs-cart.com/latest/user_guide/manage_products/products/product_attributes.html).
- Proposed equivalent: a core product concept managed through admin operations, with explicit relationships to other commerce concepts.
- Disposition: keep central catalog identity; modernize layering and typed contracts; reject cloning its schema or treating every field as an opaque payload.
- Open questions: product types, required fields, ownership scope, localization and the relationship to sellable variation identity.

## Categories

- CS-Cart concept/problem: organize discovery through a category tree.
- Behavior: categories have parent/location, ordering, content, availability and appearance settings. [Category properties](https://docs.cs-cart.com/latest/user_guide/manage_products/categories/category_attributes.html).
- Proposed equivalent: a core relational classification hierarchy, with presentation primarily in themes.
- Disposition: keep navigable classification; modernize tree integrity and admin operations; reject copying category-specific visual block/layout storage into core.
- Open questions: tree representation, cycle prevention, deletion/reparenting, category visibility and whether membership is required.

## Product/category relationships

- CS-Cart concept/problem: expose the same merchandise in several discovery paths without duplicating it.
- Behavior: one product can belong to multiple categories; the first selected category is its main category. [Multiple categories](https://docs.cs-cart.com/latest/user_guide/manage_products/categories/displaying_same_product_under_several_categories.html), [main category behavior](https://docs.cs-cart.com/latest/user_guide/manage_products/products/product_attributes.html).
- Proposed equivalent: explicit product/category membership, with primary membership only if adopted.
- Disposition: keep multiple-classification capability as a candidate; modernize joins and constraints; reject tying identity to array order as an accidental persistence rule.
- Open questions: mandatory/optional primary category, uniqueness, category-relative product position and canonical URL implications.

## Product statuses

- CS-Cart concept/problem: control listing and direct access separately.
- Behavior: Active is listed and available, Disabled is unavailable, and Hidden can be reached by direct link without being listed. [Product status definitions](https://docs.cs-cart.com/latest/user_guide/manage_products/products/product_attributes.html).
- Proposed equivalent: an explicit publication/visibility policy, distinct from stock availability and authorization.
- Disposition: keep meaningful lifecycle distinctions; modernize documented transitions and server enforcement; reject assuming “hidden” is private or out of stock is a publication status.
- Open questions: exact vocabulary, drafts/archiving, direct-link behavior, purchasability and bulk transitions.

## Product images

- CS-Cart concept/problem: present ordered merchandise imagery.
- Behavior: products have a main image, ordered additional images, generated thumbnails and editable alternative text. [Images in product properties](https://docs.cs-cart.com/latest/user_guide/manage_products/products/product_attributes.html).
- Proposed equivalent: explicit media associations with ordering, role and accessible metadata; files served through an appropriate storage layer.
- Disposition: keep gallery semantics; modernize validation and media ownership; reject copying legacy format/size limits or a generic layout blob.
- Open questions: file storage, shared media versus owned copies, variation overrides, derivative generation and removal semantics.

## Filters

- CS-Cart concept/problem: narrow catalog discovery by meaningful data.
- Behavior: filter definitions select a feature and display settings, with optional category applicability; storefront search can expose relevant active filters across categories. [Filter properties](https://docs.cs-cart.com/latest/user_guide/manage_products/filters/filter_attributes.html).
- Proposed equivalent: filters backed by explicit feature/commerce queries; presentation is a separate concern.
- Disposition: keep domain-aware filtering; modernize typed query rules and PostgreSQL access paths; reject premature external search infrastructure or using UI filter metadata as product truth.
- Open questions: first filter types, facet count semantics, category scope, product-versus-variation results and whether reusable definitions need persistence.

