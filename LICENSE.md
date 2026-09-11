# Licence

Copyright (c) 2026 Brent Passmore. All rights reserved.

The source of this software is published so that it can be installed with
Composer, inspected by the people who rely on it, and audited by the people they
answer to. Publishing the source is not a grant of ownership.

**This software is sold.** A licence is bought on the Statamic Marketplace and
covers one production site. Local development and continuous integration are
free, as Statamic's own terms allow, so you can try it before you buy it.

Permission is granted to any person holding a licence for this software (the
"Software") to use, copy and modify it on the site the licence covers, subject
to the conditions below.

1. **One licence, one production site.** Each production installation needs its
   own licence. Statamic shows a notice in the control panel when one is
   missing; the Software itself does not stop working, and that is deliberate.

2. **Not for resale or reuse in another product.** The Software, in whole or in
   part, may not be redistributed, resold, sublicensed, or reused as the basis
   of another product without written permission.

   Reading the code to learn from it is expected and encouraged. Shipping it as
   your own is not.

3. **Keep the notice.** This licence and the copyright notice stay with any copy
   or substantial portion of the Software.

4. **Follow the law.** Use of the Software must not break any applicable law or
   regulation, nor infringe anyone else's rights.

Failing any of these conditions ends the permission granted here, immediately and
automatically.

## No warranty, and specifically what is and is not restricted

The capitals are below, as usual. This part is in plain words because it is the
part people actually need.

This Software restricts what a signed-in user can see and do **in the Statamic
control panel**. It does not hide content from visitors to the site. It does not
restrict Statamic's REST API or GraphQL API, which authenticate with a shared
token rather than a user and so have no user to restrict. It does not govern
assets. Content that must not be seen by the public, or must not be reachable
through an API the site has switched on, needs its own protection; this Software
is not it and does not claim to be.

The Software works by reaching into parts of Statamic that are not a stable,
published interface. It is verified against specific Statamic versions and its
Composer constraint names them. Running it against a version it has not been
verified on is a decision the site makes, and the Software may then restrict
less than it did before without saying so. Keeping to verified versions, and
re-verifying after upgrades, is the site's responsibility.

Super users bypass every restriction, by Statamic's design and by this
Software's. So does any user holding the `configure collections` permission,
which Statamic treats as equivalent. Who holds those is the site's decision.

Where the Software reports that an account or a department is restricted to a
part of the site, that report is only as good as the Software's coverage of the
control panel on the version it is running against, which the documentation
describes. Deciding whether a particular arrangement of accounts, roles and
departments meets a legal, contractual or accreditation obligation is work for a
person, and this Software does not do it, replace it, or provide evidence
sufficient for it on its own.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS
FOR A PARTICULAR PURPOSE, COMPLETENESS OF RESTRICTION, AND NONINFRINGEMENT. IN NO
EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR
OTHER LIABILITY, INCLUDING SPECIAL, INCIDENTAL AND CONSEQUENTIAL DAMAGES, WHETHER
IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN
CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

---

*This licence was drafted by reading Statamic's own, which is the norm this addon
is listed alongside. No lawyer has reviewed it. The clause most likely to be
tested one day is the scope one: a site will switch on the REST API, or upgrade
Statamic past the verified range, and somebody will read "restricted to
cardiology" as "cannot be reached from anywhere".*
