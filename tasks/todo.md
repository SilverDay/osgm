# OSGridManager — Task Tracker

Status: `[ ]` open · `[x]` done · `[!]` blocked

---

## Phase 1: Foundation
- [x] Directory structure
- [x] `/etc/osgridmanager/config.php` → moved to `config/config.php`
- [x] `src/Core/Logger.php`
- [x] `src/Core/Config.php`
- [x] `src/Core/DB.php`
- [x] `src/Core/Request.php` + `Response.php`
- [x] `src/Core/Router.php`
- [x] `src/Core/Validator.php`
- [x] `src/Core/RateLimit.php`
- [x] `src/Core/Csrf.php`
- [x] `src/Core/Session.php`
- [x] `src/Core/Auth.php`
- [x] `public/index.php` front controller
- [x] `public/.htaccess`
- [x] `templates/layout.php`
- [x] `schema/ogm_schema.sql`
- [x] `public/assets/css/main.css` + `js/main.js`
- [x] Migration system (`scripts/migrate.php`, `schema/migrations/001`, `002`)
- [x] `deploy/mariadb_setup.sql`
- [x] `README.md`
- [x] `.gitignore`
- [x] `CLAUDE.md` + `tasks/` structure

---

## Phase 2: User & Auth
- [ ] `src/Modules/User/UserModel.php`
- [ ] `src/Modules/User/UserController.php`
- [ ] `templates/user/login.php`
- [ ] `templates/user/logout.php`
- [ ] `templates/user/account.php`
- [ ] Test: login with OpenSim credentials

## Phase 2b: Registration & User Levels
- [ ] `src/Core/Mailer.php`
- [ ] `src/Modules/Registration/RegistrationModel.php`
- [ ] `src/Modules/Registration/RegistrationController.php`
- [ ] Templates: register, verify, pending, approved, rejected
- [ ] Templates: email plaintext (verify, admin notify, approval, rejection)
- [ ] `src/Admin/AdminRegistrationController.php`
- [ ] Templates: admin registration queue
- [ ] `scripts/expire_registrations.php`

## Phase 3: Profile
- [ ] `src/Modules/Profile/ProfileModel.php`
- [ ] `src/Modules/Profile/ProfileController.php`
- [ ] Templates: profile view, profile edit
- [ ] `xmlrpc/profile.php`

## Phase 4: Region Management
- [ ] `src/Modules/Region/RegionModel.php`
- [ ] `src/Modules/Region/RegionController.php`
- [ ] Templates: region list, region detail

## Phase 4b: Land Management
- [ ] `src/Modules/Land/LandModel.php`
- [ ] `src/Modules/Land/ParcelAccessModel.php`
- [ ] `src/Modules/Land/LeaseModel.php`
- [ ] `src/Modules/Land/LandController.php`
- [ ] Templates: admin/land/, land/

## Phase 5: Economy
- [ ] `src/Modules/Economy/EconomyModel.php`
- [ ] `src/Modules/Economy/EconomyController.php`
- [ ] `src/Modules/Economy/EconomyService.php`
- [ ] Templates: wallet, history, transfer
- [ ] `xmlrpc/economy.php`

## Phase 6: Messaging & Notifications
- [ ] `src/Modules/Messaging/MessagingModel.php`
- [ ] `src/Modules/Messaging/MessagingController.php`
- [ ] `src/Modules/Notifications/NotificationService.php`
- [ ] Templates: inbox, sent, compose, read, notifications

## Phase 7: REST API
- [ ] `api/index.php`
- [ ] `api/middleware/TokenAuth.php`
- [ ] `api/middleware/RateLimit.php`
- [ ] `api/v1/auth.php`
- [ ] `api/v1/economy.php`
- [ ] `api/v1/messaging.php`
- [ ] `api/v1/profile.php`
- [ ] `api/v1/region.php`
- [ ] `api/v1/search.php`

## Phase 8: Search
- [ ] `src/Modules/Search/SearchModel.php`
- [ ] `src/Modules/Search/SearchController.php`
- [ ] `scripts/rebuild_search_cache.php`
- [ ] Templates: search results

## Phase 9: Hypergrid ACL
- [ ] `src/Modules/HypergridACL/HypergridACLModel.php`
- [ ] `src/Modules/HypergridACL/HypergridACLController.php`
- [ ] `api/v1/hypergrid.php`

## Phase 10: Admin Panel
- [ ] `src/Admin/AdminAuth.php` (TOTP)
- [ ] Admin controllers: users, regions, land, economy, config, tokens, audit, registrations
- [ ] Admin templates
- [ ] `admin/index.php`

## Phase 11: Scripts & Deploy
- [ ] All cron scripts
- [ ] Apache vhost config (`deploy/apache_vhost.conf`)
- [ ] `deploy/cron.d_osgridmanager`
- [ ] `examples/ogm_listener.lsl`
