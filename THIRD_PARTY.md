# Third-party notices

Ambresoft Commerce uses Laravel's MIT-licensed application skeleton and framework.
Laravel skeleton copyright Taylor Otwell; permission and warranty text:
[upstream framework MIT license](https://github.com/laravel/framework/blob/13.x/LICENSE.md);
the skeleton declares MIT in its [Composer manifest](https://github.com/laravel/laravel/blob/13.x/composer.json).
The framework and other installed dependencies retain their respective licenses.
Ambresoft Commerce itself is licensed under the [GNU Affero General Public License v3.0](LICENSE)
(AGPL-3.0-only). That license does not override the licenses or rights applicable to third-party components.

For copied Laravel skeleton portions:

Copyright (c) Taylor Otwell

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in
all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.

Before distributing a build, inventory locked PHP/JavaScript dependencies
and include their required notices/licenses. No CS-Cart source code is included.

## Stripe PHP library

The bundled Stripe Checkout add-on uses [`stripe/stripe-php`](https://github.com/stripe/stripe-php), the official Stripe
server-side library (MIT License, Copyright (c) 2010-2019 Stripe, Inc.). Its license text ships in `vendor/stripe/stripe-php/LICENSE`.

## Typesense Server

Product search uses [Typesense](https://github.com/typesense/typesense) Server, licensed under the **GNU General Public
License v3.0** (Copyright Typesense, Inc. and contributors). Ambresoft Commerce does not include, link to or modify its source code.
The server runs as a separate, unmodified process (the official `typesense/typesense:30.2` Docker image in `compose.yaml`,
or any independently hosted Typesense). The bundled `ambresoft/typesense` add-on talks to it only over its documented
HTTP API.

Shipping an Ambresoft Commerce installer, image bundle or appliance that redistributes the Typesense server binary or image brings
the GPL-3.0 obligations for that component (license text, source availability for the redistributed version).
Connecting to a separately deployed Typesense needs no such distribution. The product owner must have any
redistribution package reviewed legally before release.

## CodeMirror 6

The admin theme source editor uses the modular CodeMirror 6 editor and Lezer syntax highlighter (MIT, Marijn Haverbeke and contributors). Their license notices ship in the installed `@codemirror/*` and `@lezer/*` packages and must accompany distribution. Only editor/view, commands, language, search and CSS/JavaScript syntax modules are used; no IDE, language server or React wrapper is included. [Official documentation](https://codemirror.net/docs/).


## FrankenPHP and Caddy

The Docker runtime uses the official [`dunglas/frankenphp`](https://github.com/php/frankenphp) image. FrankenPHP is MIT-licensed; [its license](https://github.com/php/frankenphp/blob/main/LICENSE) remains applicable. The runtime includes [Caddy](https://github.com/caddyserver/caddy), licensed under [Apache-2.0](https://github.com/caddyserver/caddy/blob/master/LICENSE), plus the image's PHP and other component notices. Preserve the upstream licenses/notices when redistributing the runtime image. These component licenses do not change Ambresoft Commerce's AGPL-3.0 license.
