# CS-Cart comparison: features, options and variations

Researched 2026-10-08. Current public docs generally identify 4.21.x. A linked option-combination page is labeled 4.19.x and explicitly marks the behavior removed; historical 4.7.x documentation is used only to explain the old model. Our equivalents and keep/modernize/reject judgments are proposals, not automatic scope adoption.

## Features

- CS-Cart concept/problem: describe merchandise with facts useful for information, comparison, filtering and variation axes.
- Behavior: feature purposes include search/filtering, information, brand pages and two modes of variation presentation. [Feature properties](https://docs.cs-cart.com/latest/user_guide/manage_products/features/feature_attributes.html).
- Proposed equivalent: typed descriptive feature definitions, with explicit applicability and separate presentation decisions.
- Disposition: keep descriptive semantics; modernize type and purpose modeling; reject collapsing features into customer customization options.
- Open questions: supported types, category applicability, whether variation-axis eligibility is a flag or separate relationship, and presentation ownership.

## Feature groups

- CS-Cart concept/problem: organize related definitions for merchant management.
- Behavior: features can belong to a group; deleting the group also deletes its features. Category applicability can affect product values, with warnings about potential removal introduced in 4.20.1. [Groups](https://docs.cs-cart.com/latest/user_guide/manage_products/features/feature_attributes.html), [category interactions](https://docs.cs-cart.com/latest/user_guide/manage_products/features/feature_interaction.html).
- Proposed equivalent: explicit organization of features and deliberate applicability rules.
- Disposition: keep grouping; modernize change-impact feedback; reject adopting destructive group/category cascades without a deliberate retention policy.
- Open questions: one or many groups, inherited applicability, deletion behavior and retention of now-inapplicable values.

## Feature values / feature variants

- CS-Cart concept/problem: give merchants consistent descriptive values instead of spelling the same concept differently.
- Behavior: some feature types offer predefined variants; brand variants may have image, description and page metadata. [Feature variants](https://docs.cs-cart.com/latest/user_guide/manage_products/features/feature_attributes.html).
- Proposed equivalent: distinguish a feature's reusable value from the product's typed assignment. Support scalar values only where the feature type calls for them.
- Disposition: keep controlled vocabularies; modernize typed constraints and explicit assignments; reject using “variant” without qualifying whether it means feature value, option choice or merchandise.
- Open questions: multi-values, units, localization, value merging, deleted values and brand entity versus specialized feature.

## Product options

- CS-Cart concept/problem: collect customer choice or input when purchasing.
- Behavior: options include select/radio/checkbox and text/textarea/file types, with requiredness and missing-variant handling. One documented mode can hide an unavailable required option and still permit purchase. [Option properties](https://docs.cs-cart.com/latest/user_guide/manage_products/options/option_attributes.html).
- Proposed equivalent: explicit customer-choice definitions and server validation, independent of descriptive features.
- Disposition: keep customization semantics; modernize input contracts; reject silently treating a hidden required field as satisfied without an explicit business decision.
- Open questions: first supported input types, reusable definitions versus local copies, overrides, upload handling and required-choice failure behavior.

## Option variants

- CS-Cart concept/problem: offer known choices and optional purchase adjustments.
- Behavior: predefined variants can have status, icons, price modifiers and weight modifiers; modifiers can be absolute or percentage based. [Variant properties](https://docs.cs-cart.com/latest/user_guide/manage_products/options/option_attributes.html).
- Proposed equivalent: choices owned by an option, optionally feeding an explicit calculation contract.
- Disposition: keep known choices; modernize modifier evaluation and provenance; reject assuming each choice is a SKU or treating a client-supplied modifier as authoritative.
- Open questions: modifier types, calculation order/rounding, inheritance and variant deletion when already referenced.

## Option combinations: separate the two meanings

- CS-Cart concept/problem: choice tuples express compatibility; historical inventory combinations distinguished stock within a product.
- Current behavior: allowed/forbidden exceptions restrict purchasable tuples. Only select/radio/checkbox options participate, and display can hide invalid choices or warn. [Exceptions](https://docs.cs-cart.com/latest/user_guide/manage_products/options/exceptions.html).
- Historical behavior: the linked combination article says inventory option combinations no longer exist starting in 4.12.1 and directs users to variations. Older docs describe combination-level stock, a single image and prices derived from option modifiers. [Removal notice](https://docs.cs-cart.com/latest/user_guide/manage_products/options/option_combinations.html), [historical 4.7.x comparison](https://docs.cs-cart.com/4.7.x/user_guide/manage_products/products/simple_versus_configurable.html).
- Proposed equivalent: keep option tuple/validity semantics explicit; decide separately whether this CMS needs persistent stock-bearing combinations alongside variations.
- Disposition: keep compatibility rules conceptually; modernize explicit representations and validation; reject presenting removed stock behavior as current CS-Cart or copying its old tables.
- Open questions: allowlist versus denylist, wildcard rules, regeneration semantics, identity/uniqueness of tuples and overlap with variation stock. The user's phase 10 remains planned; research does not remove it.

## Product variations

- CS-Cart concept/problem: group related merchandise with different feature values and control listing density.
- Behavior: the Product Variations addon groups products by feature values. A product belongs to one group at a time; variations may occupy separate catalog positions or be selected within one. Removing a variation from the group makes it independent; default variations supply inherited properties. [Current variations](https://docs.cs-cart.com/latest/user_guide/manage_products/products/product_variations.html).
- Proposed equivalent: core variation semantics with explicit distinguishing values, sellable identity and catalog presentation policy.
- Disposition: keep related-merchandise selection; modernize inheritance and grouping rules; reject making essential variation semantics optional solely because CS-Cart packages them as an addon. Do not copy its table model.
- Open questions: products-as-variations versus dedicated variant entities, group membership, axes, allowed sparse tuples, shared versus overridden fields, stock/price identity and catalog visibility.

