# CS-Cart comparison: extensions and storefront

Researched 2026-10-08 against authoritative public 4.21.x documentation. Facts about upstream do not fix our package format, implementation or product scope. Our equivalents and dispositions below are conceptual proposals except the user's explicit Laravel-native, core-domain and no-page-builder direction.

## Addons

- CS-Cart concept/problem: add optional functionality without changing core files.
- Behavior: addon metadata declares identity/version, compatibility, dependencies, settings and lifecycle work. Management distinguishes installation, activation, deactivation and uninstall; installation registers settings/translations and can perform database work. [Addon scheme](https://docs.cs-cart.com/latest/developer_guide/addons/scheme/scheme3.0_structure.html), [management lifecycle](https://docs.cs-cart.com/latest/developer_guide/addons/addon_management.html).
- Proposed equivalent: Laravel-native modules with a manifest, provider, owned migrations and services plus explicit contributions to ecommerce workflows.
- Disposition: keep extensibility and lifecycle distinctions; modernize typed contracts and recoverable migrations; reject copying XML-embedded SQL, global registries or making every core entity an addon.
- Open questions: package discovery/distribution, manifest format, dependencies/conflicts, compatibility, settings scope, retention and install authority. Consult the modules skill for design guidance.

## PHP hooks, events and behavioral extensions

- CS-Cart concept/problem: let addons participate at defined points in execution.
- Behavior: PHP hooks hand control and named parameters to addon handlers registered under global naming conventions. Handlers can participate within the main operation. [PHP hooks](https://docs.cs-cart.com/latest/developer_guide/addons/hooking/php_hooks.html).
- Proposed equivalent: Laravel events for meaningful facts, focused synchronous extension contracts for intentional decisions/transformations, and container-bound strategies for replaceable behavior where justified.
- Disposition: keep explicit extension points; modernize types, timing, error and ordering contracts; reject global function conventions, arbitrary mutable parameter bags and relying on listener order for commerce correctness.
- Open questions: minimum initial extension surface, veto behavior, composition/priority, exceptions, after-commit timing and compatibility guarantees. A hook is not automatically an event, and an event is not automatically asynchronous.

## Template hooks and UI extensions

- CS-Cart concept/problem: let addons supplement admin/storefront presentation without core template edits.
- Behavior: template hook handlers can add content before/after a hook or override it, selected by file placement; whole template overrides are also supported. [TPL hooks](https://docs.cs-cart.com/latest/developer_guide/addons/hooking/tpl_hooks.html).
- Proposed equivalent: named component extension positions with explicit typed props and authorization-aware server contributions; storefront details will be decided later.
- Disposition: keep composable presentation; modernize React/component contracts; reject copying Smarty files, filesystem naming magic or treating unrestricted whole-view replacement as the default extension mechanism.
- Open questions: build-time component discovery, allowed positions, ordering/conflicts and theme override policy. No runtime code delivery or UI registry has been implemented.

## Themes / storefront behavior

- CS-Cart concept/problem: separate storefront appearance from merchant data and offer customization.
- Behavior: merchants can install and activate themes/styles. CS-Cart layouts use containers, grids and blocks moved through a visual editor, with layout import/export and addon layouts. [Themes](https://docs.cs-cart.com/latest/user_guide/look_and_feel/themes/overview.html), [layout architecture](https://docs.cs-cart.com/latest/designer_guide/layout.html).
- Proposed equivalent: a later theme/component layer consuming authorized commerce data, with deliberate extension positions and possibly bounded configuration.
- Disposition: keep separation of storefront presentation and core commerce; modernize component composition and theme contracts; explicitly reject a core drag-and-drop/database-driven visual page builder and arbitrary layout blobs.
- Open questions: rendering strategy, theme packaging/inheritance, assets, versioning, allowed settings and addon compatibility. React in admin does not silently select the storefront theme engine, SSR strategy or page structure.

