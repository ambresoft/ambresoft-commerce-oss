<?php

namespace App\Audit\Infrastructure;

final class AuditTables
{
    /** HTML bodies are intentionally excluded from audit history.
     * @return array<string, array{string,string}> */
    public static function storefrontTheme(): array
    {
        return ['storefront_theme_settings' => ['storefront_theme', 'primary_color,primary_hover_color,on_primary_color,text_color,background_color,sale_color,lock_version']];
    }

    /** Banner button hover effect and its transition time.
     * @return array<string, array{string, string}> */
    public static function bannerButtonHover(): array
    {
        return ['banners' => ['banner', self::banners()['banners'][1].',button_hover,button_transition']];
    }

    /** The colour of the customer sign-in and registration header (empty follows the store's primary colour).
     * @return array<string, array{string, string}> */
    public static function authHeader(): array
    {
        return ['storefront_theme_settings' => ['storefront_theme', self::storefrontTheme()['storefront_theme_settings'][1].',auth_header_color']];
    }

    /** Layout page settings: the extended layout row (the custom HTML is not captured) and its texts.
     * @return array<string, array{string, string}> */
    public static function storefrontLayoutSettings(): array
    {
        return [
            'storefront_layouts' => ['storefront_layout', 'page_type,lock_version,page_id,position,settings_version,indexable'],
            'storefront_layout_translations' => ['storefront_layout_translation', 'layout_id,locale_id,name,page_title,meta_description,meta_keywords'],
        ];
    }

    /** @return array<string, array{string, string}> */
    public static function storefront(): array
    {
        return [
            'storefront_layouts' => ['storefront_layout', 'page_type,lock_version'],
            'storefront_grids' => ['storefront_grid', 'layout_id,parent_id,region,width,row,position'],
            'storefront_blocks' => ['storefront_block', 'layout_id,type,name,template,css_class'],
            'storefront_block_translations' => ['storefront_block_translation', 'block_id,locale_id,title'],
            'storefront_block_selections' => ['storefront_selection', 'block_id,field,position,product_id,category_id,banner_id,banner_group_id,menu_id,newsletter_list_id'],
            'storefront_placements' => ['storefront_placement', 'layout_id,grid_id,block_id,position,enabled,mobile,tablet,desktop'],
            'storefront_overrides' => ['storefront_override', 'layout_id,placement_id,enabled,block_id'],
        ];
    }

    /** Explicit persisted fields; adding a table requires a privacy review and migration.
     * @return array<string, array{string, string}>
     */
    public static function all(): array
    {
        return [
            'users' => ['user', 'name,email,email_verified_at,is_active,preferred_locale_id,password'],
            'roles' => ['role', 'name,slug,description,is_super_admin'],
            'permissions' => ['permission', 'name'],
            'role_user' => ['user_role', 'user_id,role_id'],
            'permission_role' => ['role_permission', 'permission_id,role_id'],
            'locales' => ['language', 'code,native_name,is_active,is_default,direction'],
            'ui_translation_overrides' => ['ui_translation', 'locale_id,namespace,group,key,value'],
            'categories' => ['category', 'parent_id,position,status,image_media_id,age_verification,age_limit,system_key'],
            'category_translations' => ['category_translation', 'category_id,locale_id,name,slug,description,seo_title,seo_description,meta_keywords,image_alt,age_warning_message'],
            'products' => ['product', 'type,sku,ean,mpn,returnable,status,price,category_id,weight,length,width,height,deleted_at'],
            'product_translations' => ['product_translation', 'product_id,locale_id,name,slug,description,seo_title,seo_description,meta_keywords'],
            'product_images' => ['product_image', 'product_id,media_file_id,position,is_primary'],
            'media_files' => ['media', 'mime_type,byte_size,width,height'],
            'private_product_files' => ['private_file', 'mime_type,byte_size,ready'],
            'product_files' => ['product_file', 'product_id,private_file_id,display_name,position'],
            'currencies' => ['currency', 'code,name,symbol,symbol_position,thousands_separator,decimal_separator,decimals,rate,status,is_primary'],
            'features' => ['feature', 'code,type,status,group_id,position,unit,required,filterable,comparison,purpose'],
            'feature_translations' => ['feature_translation', 'feature_id,locale_id,name,description'],
            'feature_groups' => ['feature_group', 'system_key,position'],
            'feature_group_translations' => ['feature_group_translation', 'group_id,locale_id,name,description'],
            'feature_options' => ['feature_option', 'feature_id,status,position,landing_image_media_id'],
            'feature_option_translations' => ['feature_option_translation', 'option_id,locale_id,label,description,slug,seo_title,seo_description,meta_keywords'],
            'feature_categories' => ['feature_category', 'feature_id,category_id'],
            'product_feature_assignments' => ['product_feature', 'product_id,feature_id,type,is_applicable,number_value,boolean_value'],
            'product_feature_texts' => ['product_feature_text', 'assignment_id,locale_id,value'],
            'product_feature_choices' => ['product_feature_choice', 'assignment_id,feature_id,option_id,type'],
        ];
    }

    /** Phase 6 additions, attached by their own additive migration (the list above stays as migrated).
     * @return array<string, array{string, string}>
     */
    public static function variationGroups(): array
    {
        return [
            'features' => ['feature', 'code,type,status,group_id,position,unit,required,filterable,comparison,purpose,variation_style'],
            'feature_options' => ['feature_option', 'feature_id,status,position,landing_image_media_id,swatch_color,swatch_image_media_id'],
            'variation_groups' => ['variation_group', 'name'],
            'variation_group_features' => ['variation_group_feature', 'group_id,feature_id,position'],
            'variation_group_members' => ['variation_group_member', 'group_id,product_id,signature'],
            'variation_group_intents' => ['variation_group_intent', 'product_id,group_id'],
        ];
    }

    /** Phase 7 additions: product stock and ordering fields (additive migration 001600).
     * @return array<string, array{string, string}>
     */
    public static function stock(): array
    {
        return [
            'products' => ['product', self::all()['products'][1].',list_price,quantity,min_qty,max_qty,qty_step,out_of_stock_actions'],
        ];
    }

    /** Phase 7 follow-up: installation-wide general settings (additive migration 001700).
     * @return array<string, array{string, string}>
     */
    public static function settings(): array
    {
        return ['general_settings' => ['general_settings', 'out_of_stock_action']];
    }

    /** Phase 8 additions: taxes, reusable rate areas, product assignments and the tax settings columns (additive migration 001800).
     * `general_settings` supersedes its earlier field list.
     *
     * @return array<string, array{string, string}>
     */
    public static function taxes(): array
    {
        return [
            'rate_areas' => ['rate_area', 'status,is_default'],
            'rate_area_translations' => ['rate_area_translation', 'rate_area_id,locale_id,name'],
            'rate_area_countries' => ['rate_area_country', 'rate_area_id,country_code'],
            'rate_area_regions' => ['rate_area_region', 'rate_area_id,country_code,region_code'],
            'taxes' => ['tax', 'registration_number,priority,status,price_includes_tax,address_basis'],
            'tax_translations' => ['tax_translation', 'tax_id,locale_id,name'],
            'tax_rates' => ['tax_rate', 'tax_id,rate_area_id,rate_type,percent_rate,fixed_amount'],
            'product_taxes' => ['product_tax', 'product_id,tax_id'],
            'general_settings' => ['general_settings', 'out_of_stock_action,tax_calculation_method,display_catalog_prices_with_tax,display_cart_prices_with_tax,tax_estimate_without_address,tax_default_country,tax_default_region'],
        ];
    }

    /** Phase 9 additions (additive migration 001900). Personal data is kept out of the ledger: names, email, phone,
     * notes and address lines are not captured; status, tax exemption, defaults, group membership and address country are.
     *
     * @return array<string, array{string, string}>
     */
    public static function customers(): array
    {
        return [
            'customer_groups' => ['customer_group', 'status,is_default'],
            'customer_group_translations' => ['customer_group_translation', 'group_id,locale_id,name'],
            'customers' => ['customer', 'status,tax_exempt,default_billing_address_id,default_shipping_address_id'],
            'customer_addresses' => ['customer_address', 'customer_id,country_code'],
            'customer_group_members' => ['customer_group_member', 'customer_id,group_id'],
        ];
    }

    /** Customer sign-in accounts (migration 2026_10_28_000100): the identity link is captured; pending registration
     * names are personal data and are not.
     *
     * @return array<string, array{string, string}>
     */
    public static function customerAccounts(): array
    {
        return ['customers' => ['customer', self::customers()['customers'][1].',user_id']];
    }

    /** A customer's own account deletion (a soft delete), retaining everything audited before.
     *
     * @return array<string, array{string, string}>
     */
    public static function customerDeletion(): array
    {
        return ['customers' => ['customer', self::customerAccounts()['customers'][1].',deleted_at']];
    }

    /** Phase 9 blacklist (additive migration 002000). The identifier and reason are personal data and stay out of the
     * ledger; kind, source customer and release are recorded.
     *
     * @return array<string, array{string, string}>
     */
    public static function customerBlacklist(): array
    {
        return ['customer_blacklist_entries' => ['customer_blacklist_entry', 'kind,customer_id,released_at']];
    }

    /** Company contact data is merchant-provided business information; no authentication secrets.
     * @return array<string, array{string, string}>
     */
    public static function company(): array
    {
        return ['company_settings' => ['company_settings', 'name,address,city,country_code,region,postal_code,phone,mobile,website,opening_year,email_customers,email_admin,email_orders,email_support,email_newsletter,logo_media_id,logo_mobile_media_id,logo_email_media_id']];
    }

    /** Footer payment pictures (migration 2026_10_30_000300).
     * @return array<string,array{string,string}> */
    public static function companyPaymentIcons(): array
    {
        return ['company_payment_icons' => ['company_payment_icon', 'media_file_id,name,position']];
    }

    /** Ordered social pages and app links of the store footer (replaced the per-network icons).
     * @return array<string,array{string,string}> */
    public static function companyLinks(): array
    {
        return ['company_links' => ['company_link', 'kind,preset,name,url,media_file_id,position']];
    }

    /** @return array<string,array{string,string}> */
    public static function companySocialIcons(): array
    {
        return ['company_social_icons' => ['company_social_icon', 'network,media_file_id']];
    }

    /** Website menu configuration; URLs are bounded user-selected targets, never credentials.
     * @return array<string,array{string,string}>
     */
    public static function menus(): array
    {
        return [
            'website_menus' => ['menu', 'status,placement,source'],
            'website_menu_translations' => ['menu_translation', 'menu_id,locale_id,name'],
            'website_menu_items' => ['menu_item', 'menu_id,parent_id,status,position,kind,url,category_id,generate_children,new_window,image_media_id'],
            'website_menu_item_translations' => ['menu_item_translation', 'item_id,locale_id,name,description,image_alt'],
        ];
    }

    /** Shipping countries and regions (nomoi): merchant configuration, no personal data.
     *
     * @return array<string,array{string,string}>
     */
    public static function geography(): array
    {
        return [
            'shipping_countries' => ['shipping_country', 'country_code'],
            'regions' => ['region', 'country_code,code,status'],
            'region_translations' => ['region_translation', 'region_id,locale_id,name'],
        ];
    }

    /** Marketing banners. Long texts are recorded as redacted lengths by the ledger's safe-value policy.
     *
     * @return array<string,array{string,string}>
     */
    public static function banners(): array
    {
        return [
            'banner_groups' => ['banner_group', 'name'],
            'banners' => ['banner', 'group_id,position,status,available_from,available_until,object_type,url,tablet_custom,phone_custom'],
            'banner_translations' => ['banner_translation', 'banner_id,locale_id,name'],
            'banner_layouts' => ['banner_layout', 'banner_id,device,valign,halign,paddings,full_width,background_type,button_enabled,mask_type,css_classes'],
            'banner_contents' => ['banner_content', 'banner_id,device,locale_id,title,description,button_text,youtube_video,main_image_media_id,background_image_media_id,background_video_id'],
            'banner_products' => ['banner_product', 'banner_id,product_id,position'],
            'banner_customer_groups' => ['banner_customer_group', 'banner_id,group_id'],
        ];
    }

    /** Content pages. Page HTML and long meta texts are recorded as redacted lengths by the safe-value policy.
     *
     * @return array<string,array{string,string}>
     */
    public static function pages(): array
    {
        return [
            'pages' => ['page', 'parent_id,position,status,available_from,available_until,facebook_type'],
            'page_translations' => ['page_translation', 'page_id,locale_id,name,slug,seo_title,content,meta_description,meta_keywords'],
            'page_customer_groups' => ['page_customer_group', 'page_id,group_id'],
        ];
    }

    /** @return array<string,array{string,string}> */
    public static function blogs(): array
    {
        return [
            'blogs' => ['blog', 'position,status,available_from,available_until,facebook_type'],
            'blog_translations' => ['blog_translation', 'blog_id,locale_id,name,slug,seo_title,content,meta_description,meta_keywords'],
            'blog_posts' => ['blog_post', 'blog_id,status,published_at,available_from,available_until,facebook_type,cover_media_id'],
            'blog_post_translations' => ['blog_post_translation', 'post_id,locale_id,name,slug,seo_title,content,excerpt,cover_alt,meta_description,meta_keywords'],
            'blog_post_tags' => ['blog_post_tag', 'post_id,locale_id,name'],
        ];
    }

    /** Page form builder definitions. Recipient addresses are merchant configuration (the store's own mailboxes).
     *
     * @return array<string,array{string,string}>
     */
    public static function pageForms(): array
    {
        return [
            'page_forms' => ['page_form', 'page_id,enabled,placement,subject_mode,subject_field_id,recipients'],
            'page_form_translations' => ['page_form_translation', 'page_id,locale_id,custom_subject,submit_text'],
            'page_form_fields' => ['page_form_field', 'page_id,position,type,required,status'],
            'page_form_field_translations' => ['page_form_field_translation', 'field_id,locale_id,name'],
            'page_form_variants' => ['page_form_variant', 'field_id,position'],
            'page_form_variant_translations' => ['page_form_variant_translation', 'variant_id,locale_id,label'],
        ];
    }

    /** Category device classification only; no buyer VAT identifiers or tax-calculation changes.
     * @return array<string, array{string, string}>
     */
    public static function categoryArticle39a(): array
    {
        return ['categories' => ['category', self::all()['categories'][1].',article_39a_eligible']];
    }

    /** Category fields after Article 39a eligibility left core (migration 2026_12_11_000100).
     * @return array<string, array{string, string}>
     */
    public static function categoryArticle39aRemoved(): array
    {
        return ['categories' => ['category', str_replace(',article_39a_eligible', '', self::catalogVisibility()['categories'][1])]];
    }

    /** Product shipping/packaging/release fields, category discovery exclusion and catalog customer-group restrictions
     * (additive migration 000700). Supersedes the earlier `products` and `categories` field lists.
     *
     * @return array<string, array{string, string}>
     */
    public static function catalogVisibility(): array
    {
        return [
            'products' => ['product', self::stock()['products'][1].',free_shipping,shipping_cost,available_from,box_min_items,box_max_items,box_length,box_width,box_height'],
            'categories' => ['category', self::categoryArticle39a()['categories'][1].',exclude_from_discovery'],
            'product_customer_groups' => ['product_customer_group', 'product_id,group_id'],
            'category_customer_groups' => ['category_customer_group', 'category_id,group_id'],
        ];
    }

    /** Product file purpose: buyer download or product attachment (additive migration 000800).
     *
     * @return array<string, array{string, string}>
     */
    public static function productAttachments(): array
    {
        return ['product_files' => ['product_file', self::all()['product_files'][1].',purpose']];
    }

    /** General settings expansion, including locale-linked merchant closure text.
     * @return array<string, array{string, string}>
     */
    public static function generalOptions(): array
    {
        return [
            'general_settings' => ['general_settings', self::taxes()['general_settings'][1].',storefront_open,date_format,time_format,timezone,calendar_date_format,calendar_week_start,warn_unsaved_changes,show_menu_descriptions,notification_duration,phone_validation,weight_unit,grams_per_weight_unit,low_stock_threshold,allow_product_comparison,track_inventory,single_coupon'],
            'general_settings_translations' => ['general_settings_translation', 'settings_id,locale_id,storefront_closed_message'],
        ];
    }

    /** Phase 9 orders (additive migration 2026_10_15_000100). Contact, address, note and IP snapshots are personal
     * data and stay out of the ledger; status, totals, currency, customer link and item quantities/prices are recorded.
     * Status history and notification records are their own append-only trails.
     *
     * @return array<string, array{string, string}>
     */
    public static function orders(): array
    {
        return [
            'order_statuses' => ['order_status', 'code,color,position,is_active,is_initial,is_final,is_system,stock_action'],
            'order_status_translations' => ['order_status_translation', 'status_id,locale_id,name,description'],
            'order_email_templates' => ['order_email_template', 'status_id,locale_id,subject,body_html'],
            'orders' => ['order', 'order_number,customer_id,status_id,locale_id,currency_code,subtotal,discount_total,tax_total,tax_included,shipping_total,total,tax_exempt,stock_held'],
            'order_items' => ['order_item', 'order_id,product_id,position,sku,quantity,catalog_price,unit_price,price_overridden,subtotal,tax_total,total,deducted_quantity'],
        ];
    }

    /** Order email sender settings (additive migration 2026_10_15_000200). Supersedes the earlier `general_settings` list.
     *
     * @return array<string, array{string, string}>
     */
    public static function orderEmailSender(): array
    {
        return ['general_settings' => ['general_settings', self::generalOptions()['general_settings'][1].',order_email_from,order_email_from_name,order_email_reply_to']];
    }

    /** Order email layouts and the invoice flag (additive migration 2026_10_15_000300). Supersedes `order_statuses`.
     *
     * @return array<string, array{string, string}>
     */
    public static function orderEmailLayouts(): array
    {
        return [
            'order_statuses' => ['order_status', self::orders()['order_statuses'][1].',sends_invoice'],
            'order_email_layouts' => ['order_email_layout', 'locale_id,header_html,footer_html,invoice_subject,invoice_body_html'],
        ];
    }

    /** Statuses in which customers may cancel their own order (migration 2026_11_09_000300). Supersedes `order_statuses`.
     * @return array<string, array{string, string}>
     */
    public static function customerCancellation(): array
    {
        return ['order_statuses' => ['order_status', self::orderEmailLayouts()['order_statuses'][1].',customer_can_cancel']];
    }

    /** Mail delivery settings (additive migration 2026_10_15_000500). The SMTP username and password are credentials
     * and stay out of the ledger; method, host, port, encryption, authentication flag and sendmail path are recorded.
     *
     * @return array<string, array{string, string}>
     */
    public static function mailDelivery(): array
    {
        return ['general_settings' => ['general_settings', self::orderEmailSender()['general_settings'][1].',mail_method,smtp_host,smtp_port,smtp_encryption,smtp_auth,sendmail_path']];
    }

    /** FAQ content, typed links and AI configuration; secrets and AI request/response bodies are excluded.
     * @return array<string, array{string, string}>
     */
    public static function faqs(): array
    {
        return [
            'faqs' => ['faq', 'type,status,position'],
            'faq_translations' => ['faq_translation', 'faq_id,locale_id,question,answer'],
            'category_faqs' => ['category_faq', 'category_id,faq_id,faq_type,position'],
            'product_faqs' => ['product_faq', 'product_id,faq_id,faq_type,position'],
            'page_faqs' => ['page_faq', 'page_id,faq_id,faq_type,position'],
            'faq_ai_settings' => ['faq_ai_settings', 'enabled,model,max_tokens,hourly_limit'],
        ];
    }

    /** Shared AI configuration, excluding the encrypted key.
     * @return array<string, array{string, string}>
     */
    public static function aiSettings(): array
    {
        return ['ai_settings' => ['ai_settings', 'enabled,model,max_tokens,hourly_limit']];
    }

    /** Availability labels and their product references; never quantity or purchase-policy changes.
     * @return array<string,array{string,string}>
     */
    public static function availability(): array
    {
        return [
            'availability_statuses' => ['availability_status', 'position,default_in_stock,default_out_of_stock'],
            'availability_status_translations' => ['availability_status_translation', 'status_id,locale_id,name'],
            'products' => ['product', self::catalogVisibility()['products'][1].',in_stock_status_id,out_of_stock_status_id'],
        ];
    }

    /** Phase 10 payments and add-ons (migration 2026_10_16_000100). Secrets, verification fingerprints, redirect URLs,
     * provider references and notes are not captured; `orders` gains the chosen payment method.
     *
     * @return array<string, array{string, string}>
     */
    public static function payments(): array
    {
        return [
            'addons' => ['addon', 'name,source,version,status'],
            'payment_methods' => ['payment_method', 'code,owner,is_active,position,paid_status_id,settings,verified_at'],
            'payment_method_translations' => ['payment_method_translation', 'method_id,locale_id,name,description'],
            'order_payments' => ['order_payment', 'order_id,payment_method_id,kind,state,amount,currency_code,detail'],
            'orders' => ['order', self::orders()['orders'][1].',payment_method_id'],
        ];
    }

    /** @return array<string, array{string, string}> */
    public static function productIdentifiers(): array
    {
        return ['products' => ['product', self::availability()['products'][1].',isbn,upc']];
    }

    /** Short description, the similar-products switch and frequently-bought-together companions.
     * @return array<string, array{string, string}> */
    public static function productCompanions(): array
    {
        return [
            'products' => ['product', self::productIdentifiers()['products'][1].',similar_products'],
            'product_translations' => ['product_translation', self::all()['product_translations'][1].',short_description'],
            'product_companions' => ['product_companion', 'product_id,companion_id,position'],
        ];
    }

    /** Sale times and titles, added after the original sale tables.
     * @return array<string, array{string, string}> */
    public static function productSaleTimesAndTitles(): array
    {
        return ['product_sales' => ['product_sale', 'product_id,customer_group_id,minimum_quantity,priority,kind,amount,stock_limit,starts_on,ends_on,starts_time,ends_time,is_enabled'], 'product_sale_translations' => ['product_sale_translation', 'sale_id,locale_id,title']];
    }

    /** @return array<string, array{string, string}> */
    public static function productSales(): array
    {
        return ['product_sales' => ['product_sale', 'product_id,customer_group_id,minimum_quantity,priority,kind,amount,stock_limit,starts_on,ends_on,is_enabled'], 'product_sale_allocations' => ['product_sale_allocation', 'item_id,sale_id,quantity']];
    }

    /** Private customer contact, notes and product text are deliberately excluded.
     * @return array<string, array{string, string}>
     */
    public static function callRequests(): array
    {
        return ['call_requests' => ['call_request', 'status,responsible_id,order_id,order_number'], 'call_request_products' => ['call_request_product', 'call_request_id,product_id,quantity,position']];
    }

    /** Search provider settings (migration 2026_11_10_000100); stored secrets are never captured.
     *
     * @return array<string, array{string, string}>
     */
    public static function search(): array
    {
        return ['search_provider_settings' => ['search_provider_settings', 'provider,settings']];
    }

    /** Phase 11 shipping (migration 2026_10_17_000100). Carrier credentials and account identifiers, tracking events,
     * shipment details and voucher request summaries are not captured; `orders` gains the shipping method.
     *
     * @return array<string, array{string, string}>
     */
    public static function shipping(): array
    {
        return [
            'shipping_carrier_settings' => ['shipping_carrier_settings', 'provider,settings'],
            'shipping_carrier_accounts' => ['shipping_carrier_account', 'provider,name,position,verified_at'],
            'shipping_methods' => ['shipping_method', 'calculation,provider,account_id,is_active,position,min_weight,max_weight,free_shipping_applies,carrier_amount,carrier_surcharge'],
            'shipping_method_translations' => ['shipping_method_translation', 'method_id,locale_id,name,description,delivery_time'],
            'shipping_method_rates' => ['shipping_method_rate', 'method_id,rate_area_id,weight_from,weight_to,base,per_unit'],
            'shipping_method_areas' => ['shipping_method_area', 'method_id,rate_area_id'],
            'shipping_method_taxes' => ['shipping_method_tax', 'method_id,tax_id'],
            'shipping_method_payment_methods' => ['shipping_method_payment', 'method_id,payment_method_id'],
            'shipping_method_customer_groups' => ['shipping_method_group', 'method_id,group_id'],
            'order_shipments' => ['order_shipment', 'order_id,provider,account_id,state,tracking_number,packages,tracking_state,cancelled_at'],
            'shipping_manifests' => ['shipping_manifest', 'provider,account_id,number,manifest_date,voucher_count'],
            'orders' => ['order', self::payments()['orders'][1].',shipping_method_id'],
        ];
    }

    /** Optional images of payment and shipping methods (migration 2026_10_17_000200).
     *
     * @return array<string, array{string, string}>
     */
    public static function methodImages(): array
    {
        return [
            'payment_methods' => ['payment_method', self::payments()['payment_methods'][1].',image_media_id'],
            'shipping_methods' => ['shipping_method', self::shipping()['shipping_methods'][1].',image_media_id'],
        ];
    }

    /** The product types and categories a payment method cannot pay for, retaining all previously audited fields.
     * @return array<string, array{string, string}>
     */
    public static function paymentRestrictions(): array
    {
        return [
            'payment_methods' => ['payment_method', self::methodImages()['payment_methods'][1].',exclude_physical,exclude_digital'],
            'payment_method_excluded_categories' => ['payment_method_excluded_category', 'method_id,category_id'],
        ];
    }

    /** Phase 12 newsletters (migration 2026_10_18_000100). Subscriber and sender e-mail addresses, message bodies and
     * delivery records are not captured; subscriptions are recorded by list and subscriber IDs only.
     *
     * @return array<string, array{string, string}>
     */
    public static function newsletters(): array
    {
        return [
            'newsletter_lists' => ['newsletter_list', 'status,confirmation_id,show_at_checkout,show_on_registration'],
            'newsletter_list_translations' => ['newsletter_list_translation', 'list_id,locale_id,name,description'],
            'newsletter_campaigns' => ['newsletter_campaign', 'name,status'],
            'newsletters' => ['newsletter', 'type,state,is_active,campaign_id,sent_at'],
            'newsletter_translations' => ['newsletter_translation', 'newsletter_id,locale_id,name,subject'],
            'newsletter_audiences' => ['newsletter_audience', 'newsletter_id,list_id'],
            'newsletter_audience_customers' => ['newsletter_audience_customer', 'newsletter_id,customer_id'],
            'newsletter_subscribers' => ['newsletter_subscriber', 'status,source,locale_id,customer_id,unsubscribed_at'],
            'newsletter_list_subscribers' => ['newsletter_subscription', 'list_id,subscriber_id,confirmed_at'],
        ];
    }

    /** Social login providers (migration 2026_10_19_000100). The client ID is public; the client secret lives in the
     * encrypted `secrets` column, which is never captured.
     *
     * @return array<string, array{string, string}>
     */
    public static function socialLogin(): array
    {
        return ['social_login_providers' => ['social_login_provider', 'provider,name,is_active,settings']];
    }

    /** @return array<string, array{string, string}> */
    public static function coupons(): array
    {
        return [
            'orders' => ['order', self::shipping()['orders'][1].',coupon_id,coupon_code,shipping_discount'],
            'order_items' => ['order_item', self::orders()['order_items'][1].',discount_total'],
            'coupons' => ['coupon', 'name,code,type,amount,minimum,login_required,free_shipping,all_products,starts_on,ends_on,total_limit,customer_limit,status,deleted_at'],
            'coupon_products' => ['coupon_product', 'coupon_id,product_id'],
            'coupon_categories' => ['coupon_category', 'coupon_id,category_id'],
            'coupon_redemptions' => ['coupon_redemption', 'coupon_id,order_id,code,discount,shipping_discount,currency_code,currency_decimals,released_at'],
        ];
    }

    /** Payment method fees (e.g. cash on delivery): the method's fee and the fee charged on each order.
     * @return array<string, array{string, string}> */
    public static function paymentFees(): array
    {
        return [
            'orders' => ['order', self::coupons()['orders'][1].',payment_fee'],
            'payment_methods' => ['payment_method', self::paymentRestrictions()['payment_methods'][1].',fee'],
        ];
    }

    /** Checkout consents: pages picked by the checkout's main content block, and the buyer's invoice request on the order.
     * @return array<string, array{string, string}> */
    public static function checkoutConsents(): array
    {
        return ['storefront_block_selections' => ['storefront_selection', self::popularSearches()['storefront_block_selections'][1].',page_id'],
            'orders' => ['order', self::paymentFees()['orders'][1].',invoice_requested']];
    }

    /** Payment methods taken away from a blacklisted email or phone (entry scope `payment_methods`).
     * @return array<string, array{string, string}> */
    public static function blacklistPaymentRestrictions(): array
    {
        return ['customer_blacklist_payment_methods' => ['customer_blacklist_payment_method', 'entry_id,payment_method_id']];
    }

    /** @return array<string, array{string, string}> */
    public static function seoRedirects(): array
    {
        return ['seo_redirects' => ['seo_redirect', 'source_path,destination,status_code,enabled']];
    }

    /** @return array<string, array{string, string}> */
    public static function categoryBlogPosts(): array
    {
        return ['category_blog_posts' => ['category_blog_post', 'category_id,post_id,position']];
    }

    /** Coupon and offer block selections.
     * @return array<string, array{string, string}> */
    public static function dealBlockSelections(): array
    {
        return ['storefront_block_selections' => ['storefront_selection', self::blogBlockSelections()['storefront_block_selections'][1].',coupon_id,promotion_id']];
    }

    /** @return array<string, array{string, string}> */
    public static function blogBlockSelections(): array
    {
        return ['storefront_block_selections' => ['storefront_selection', self::checkoutConsents()['storefront_block_selections'][1].',blog_id,blog_post_id']];
    }

    /** Add-on settings (the `settings` contribution); secrets stay out of the ledger.
     * @return array<string, array{string, string}> */
    public static function addonSettings(): array
    {
        return ['addon_settings' => ['addon_settings', 'addon,settings']];
    }

    /** The company's legal and tax details (shown on order summaries).
     * @return array<string, array{string, string}> */
    public static function companyTaxDetails(): array
    {
        return ['company_settings' => ['company_settings', self::company()['company_settings'][1].',legal_name,business_activity,tax_id,tax_office,gemi_number']];
    }

    /** Order email layouts with their editable invoice rows and the printable packing slip, per language.
     * @return array<string, array{string, string}> */
    public static function packingSlips(): array
    {
        return ['order_email_layouts' => ['order_email_layout', self::orderEmailLayouts()['order_email_layouts'][1].',invoice_item_html,invoice_total_html,packing_slip_body_html,packing_slip_item_html']];
    }

    /** A picture beside a block's content (product carousels).
     * @return array<string, array{string, string}> */
    public static function blockImages(): array
    {
        return ['storefront_blocks' => ['storefront_block', self::storefront()['storefront_blocks'][1].',image_media_id,image_mobile_media_id']];
    }

    /** The order subtotal above which a payment method is not offered (e.g. cash on delivery).
     * @return array<string, array{string, string}> */
    public static function paymentLimits(): array
    {
        return ['payment_methods' => ['payment_method', self::paymentFees()['payment_methods'][1].',max_subtotal']];
    }

    /** @return array<string, array{string, string}> */
    public static function stickers(): array
    {
        return [
            'stickers' => ['sticker', 'name,type,style,status,starts_at,ends_at,priority,match,image_media_id,placement,show_card,show_detail,show_desktop,show_tablet,show_mobile,background,color,border_color,border_width,radius,padding,font_size,alignment,image_width,css_class'],
            'sticker_translations' => ['sticker_translation', 'sticker_id,locale_id,text,short_text,tooltip'],
            'sticker_conditions' => ['sticker_condition', 'sticker_id,type,minimum,maximum,category_id,feature_id,option_id,include_descendants,boolean_value'],
            'product_stickers' => ['product_sticker', 'product_id,sticker_id'],
            'sticker_display_settings' => ['sticker_settings', 'card_limit,detail_limit'],
        ];
    }

    /** Product reviews (migration 2026_10_27_000100). The customer's title and text and the internal moderation note are
     * not copied; staff corrections are visible through `edited_at`/`edited_by`.
     *
     * @return array<string, array{string, string}>
     */
    public static function reviews(): array
    {
        return ['product_reviews' => ['review', 'product_id,customer_id,rating,status,verified_purchase,moderated_by,moderated_at,edited_by,edited_at']];
    }

    /** Promotion configuration and financial snapshots; no cart calculations or customer identifiers.
     * @return array<string, array{string, string}>
     */
    public static function promotions(): array
    {
        $tables = [
            'promotions' => ['promotion', 'status,mode,coupon_id,starts_at,ends_at,priority,stop_other,match,benefit,scope,amount,all_products,exclude_sale,all_shipping'],
            'promotion_translations' => ['promotion_translation', 'promotion_id,locale_id,name,description'],
            'promotion_conditions' => ['promotion_condition', 'promotion_id,type,minimum,maximum,quantity,quantity_scope,match'],
            'order_promotions' => ['order_promotion', 'order_id,promotion_id,name,coupon_code,benefit,discount,shipping_discount,currency_code,currency_decimals,released_at'],
        ];
        foreach (['products' => 'product_id', 'categories' => 'category_id', 'customer_groups' => 'group_id', 'shipping_methods' => 'shipping_method_id'] as $table => $column) {
            $tables['promotion_condition_'.$table] = ['promotion_condition_target', 'condition_id,'.$column];
            if ($table !== 'customer_groups') {
                $tables['promotion_'.$table] = ['promotion_benefit_target', 'promotion_id,'.$column.',excluded'];
            }
        }

        return $tables;
    }

    /** Gift cards (migration 2026_10_24_000100). The code is a bearer credential and the names, email and message are
     * personal data, so none of them is copied into the audit trail.
     *
     * @return array<string, array{string, string}>
     */
    public static function giftCards(): array
    {
        return [
            'gift_cards' => ['gift_card', 'currency_code,amount,balance,status,locale_id,notify,deleted_at'],
            'gift_card_transactions' => ['gift_card_transaction', 'gift_card_id,type,amount,balance_after,order_id'],
            'gift_card_emails' => ['gift_card_email', 'gift_card_id,kind,state,attempts,last_error'],
            'order_payments' => ['order_payment', self::payments()['order_payments'][1].',gift_card_id'],
        ];
    }

    /** The "new products" window, retaining all previously audited settings.
     * @return array<string, array{string, string}>
     */
    public static function newProductsWindow(): array
    {
        return ['general_settings' => ['general_settings', self::wishlistSetting()['general_settings'][1].',new_products_days']];
    }

    /** Storefront switch for showing the price without tax, retaining all previously audited settings.
     * @return array<string, array{string, string}>
     */
    public static function pricesWithoutTaxSetting(): array
    {
        return ['general_settings' => ['general_settings', self::newProductsWindow()['general_settings'][1].',show_prices_without_tax']];
    }

    /** How many products can be compared at once, retaining all previously audited settings.
     * @return array<string, array{string, string}>
     */
    public static function comparisonLimitSetting(): array
    {
        return ['general_settings' => ['general_settings', self::pricesWithoutTaxSetting()['general_settings'][1].',comparison_max_products']];
    }

    /** The store-wide switch for coupon and gift card codes in the cart, retaining all previously audited settings.
     * @return array<string, array{string, string}>
     */
    public static function cartCodesSetting(): array
    {
        return ['general_settings' => ['general_settings', self::comparisonLimitSetting()['general_settings'][1].',cart_codes_enabled']];
    }

    /** Whether checkout requires a customer account, retaining all previously audited settings.
     * @return array<string, array{string, string}>
     */
    public static function checkoutAccountSetting(): array
    {
        return ['general_settings' => ['general_settings', self::landingSourceSetting()['general_settings'][1].',checkout_requires_account']];
    }

    /** The source of root catalog landing URLs, retaining all previously audited settings.
     * @return array<string, array{string, string}>
     */
    public static function landingSourceSetting(): array
    {
        return ['general_settings' => ['general_settings', self::cartSetting()['general_settings'][1].',brand_landing_pages']];
    }

    /** The store-wide switch for the storefront cart and checkout.
     * @return array<string, array{string, string}>
     */
    public static function cartSetting(): array
    {
        return ['general_settings' => ['general_settings', self::cartCodesSetting()['general_settings'][1].',cart_enabled']];
    }

    /** The store-wide wishlist switch, retaining all previously audited settings.
     * @return array<string, array{string, string}>
     */
    public static function wishlistSetting(): array
    {
        return ['general_settings' => ['general_settings', self::backToTop()['general_settings'][1].',wishlist_enabled']];
    }

    /** Storefront scroll control, retaining all previously audited settings.
     * @return array<string, array{string, string}>
     */
    public static function backToTop(): array
    {
        return ['general_settings' => ['general_settings', self::siteWidth()['general_settings'][1].',show_back_to_top']];
    }

    /** @return array<string, array{string, string}> */
    public static function siteWidth(): array
    {
        return ['general_settings' => ['general_settings', self::productCardStyle()['general_settings'][1].',site_max_width']];
    }

    /** @return array<string, array{string, string}> */
    public static function productCardStyle(): array
    {
        return ['general_settings' => ['general_settings', self::productImageBlend()['general_settings'][1].',product_card_style']];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function productImageBlend(): array
    {
        return ['general_settings' => ['general_settings', self::priceVisibility()['general_settings'][1].',blend_product_images']];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function priceVisibility(): array
    {
        return [
            'general_settings' => ['general_settings', self::mailDelivery()['general_settings'][1].',hide_product_prices,hide_out_of_stock_listings'],
            'general_settings_translations' => ['general_settings_translation', self::generalOptions()['general_settings_translations'][1].',price_hidden_message'],
        ];
    }

    /** Brand document associations, without private storage paths or document contents.
     * @return array<string,array{string,string}> */
    public static function brandFiles(): array
    {
        return ['brand_files' => ['brand_file', 'brand_id,private_file_id,display_name,position']];
    }

    /** Brand catalog and explicit product ownership. Descriptions stay out of audit snapshots.
     * @return array<string,array{string,string}> */
    public static function brands(): array
    {
        return [
            'brands' => ['brand', 'code,status,position,image_media_id,lock_version'],
            'brand_translations' => ['brand_translation', 'brand_id,locale_id,name,image_alt'],
            'products' => ['product', self::productCompanions()['products'][1].',brand_id'],
            'storefront_block_selections' => ['storefront_selection', self::storefront()['storefront_block_selections'][1].',brand_id'],
        ];
    }

    /** Brand banner URLs are excluded from the audit ledger.
     * @return array<string,array{string,string}> */
    public static function brandBanners(): array
    {
        return [
            'brands' => ['brand', self::brands()['brands'][1].',banner_image_media_id'],
            'brand_translations' => ['brand_translation', self::brands()['brand_translations'][1].',banner_alt'],
        ];
    }

    /** Popular searches picked by storefront blocks (migration 2026_11_11_000100); search counts are never captured.
     * @return array<string, array{string, string}> */
    public static function popularSearches(): array
    {
        return ['storefront_block_selections' => ['storefront_selection', self::brands()['storefront_block_selections'][1].',search_term_id'], 'search_terms' => ['search_term', 'hidden']];
    }

    /** Reels metadata is audited; individual viewing history stays private and out of the ledger.
     * @return array<string, array{string, string}> */
    public static function reels(): array
    {
        return [
            'reels' => ['reel', 'status,video_id,cover_media_id,lock_version'],
            'reel_translations' => ['reel_translation', 'reel_id,locale_id,name'],
            'reel_products' => ['reel_product', 'reel_id,product_id,position'],
            'storefront_block_selections' => ['storefront_selection', self::dealBlockSelections()['storefront_block_selections'][1].',reel_id'],
            'general_settings' => ['general_settings', self::checkoutAccountSetting()['general_settings'][1].',reels_days'],
        ];
    }

    /** @return array<string, array{string, string}> */
    public static function bestsellers(): array
    {
        return [
            'general_settings' => ['general_settings', self::reels()['general_settings'][1].',bestsellers_days,bestsellers_min_units,bestsellers_min_orders'],
            'bestseller_excluded_categories' => ['bestseller_excluded_category', 'category_id'],
        ];
    }

    /** @return array<string, array{string, string}> */
    public static function storefrontIndexing(): array
    {
        return ['general_settings' => ['general_settings', self::bestsellers()['general_settings'][1].',storefront_noindex']];
    }

    /** Credentials and buyer tax identifiers never enter the audit payload.
     * @return array<string, array{string, string}>
     */
    public static function checkoutInvoices(): array
    {
        return ['checkout_settings' => ['checkout_settings', 'id,enabled,greek_provider,international_provider,lock_version'],
            'order_invoice_verifications' => ['order_invoice_verification', 'order_id,provider,verified_at']];
    }

    /** @return array<string, array{string, string}> */
    public static function stickerFilters(): array
    {
        return ['stickers' => ['sticker', self::stickers()['stickers'][1].',is_filter']];
    }

    /** @return array<string, array{string, string}> */
    public static function productExtraCategories(): array
    {
        return ['product_categories' => ['product_category', 'product_id,category_id,position']];
    }

    /** @return array<string, array{string, string}> */
    public static function blogMobileCovers(): array
    {
        return ['blog_posts' => ['blog_post', self::blogs()['blog_posts'][1].',cover_mobile_media_id']];
    }

    /** @return array<string, array{string, string}> */
    public static function orderItemBrands(): array
    {
        return ['order_items' => ['order_item', self::coupons()['order_items'][1].',brand_id,brand_name']];
    }

    /** @return array<string, array{string, string}> */
    public static function stickerFilterSlugs(): array
    {
        return ['stickers' => ['sticker', self::stickerFilters()['stickers'][1].',filter_slug']];
    }

    /** @return array<string, array{string, string}> */
    public static function checkoutInvoiceCountries(): array
    {
        return ['checkout_invoice_countries' => ['checkout_invoice_country', 'country_code']];
    }

    /** Every currently captured table with its effective reviewed fields.
     * @return array<string, array{string, string}>
     */
    public static function inventory(): array
    {
        $faqs = self::faqs();
        unset($faqs['faq_ai_settings']);

        return array_replace(self::all(), self::variationGroups(), self::stock(), self::settings(), self::taxes(), self::customers(), self::customerBlacklist(), self::company(), self::menus(), self::geography(), self::banners(), self::pages(), self::blogs(), self::pageForms(), self::categoryArticle39a(), self::catalogVisibility(), self::productAttachments(), self::generalOptions(), self::orders(), self::orderEmailSender(), self::orderEmailLayouts(), self::customerCancellation(), self::mailDelivery(), self::productImageBlend(), self::productCardStyle(), self::siteWidth(), self::backToTop(), self::wishlistSetting(), self::pricesWithoutTaxSetting(), self::checkoutAccountSetting(), $faqs, self::aiSettings(), self::availability(), self::payments(), self::productIdentifiers(), self::productCompanions(), self::productSales(), self::productSaleTimesAndTitles(), self::callRequests(), self::shipping(), self::search(), self::methodImages(), self::paymentRestrictions(), self::newsletters(), self::socialLogin(), self::coupons(), self::giftCards(), self::promotions(), self::stickers(), self::reviews(), self::customerAccounts(), self::customerDeletion(), self::storefront(), self::storefrontLayoutSettings(), self::storefrontTheme(), self::companyPaymentIcons(), self::companyLinks(), self::brands(), self::brandFiles(), self::brandBanners(), self::popularSearches(), self::paymentLimits(), self::checkoutConsents(), self::addonSettings(), self::companyTaxDetails(), self::blockImages(), self::packingSlips(), self::authHeader(), self::bannerButtonHover(), self::dealBlockSelections(), self::categoryBlogPosts(), self::seoRedirects(), self::blacklistPaymentRestrictions(), self::reels(), self::bestsellers(), self::storefrontIndexing(), self::checkoutInvoices(), self::checkoutInvoiceCountries(), self::stickerFilters(), self::stickerFilterSlugs(), self::orderItemBrands(), self::blogMobileCovers(), self::productExtraCategories(), self::categoryArticle39aRemoved());
    }
}
