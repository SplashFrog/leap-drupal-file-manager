# Splash Frog - LEAP File Manager

**Enterprise-grade media file replacement, safe staging, and SEO-preservation for Drupal 11.**

The LEAP File Manager solves the "Versioned Filename" problem in Drupal Core. By default, when you replace a file in Drupal, it creates a new file record (e.g., `image_0.jpg`), which destroys SEO backlinks and results in stale assets on the disk. 

This module provides a robust orchestrator that performs **physical file overwrites**, allowing you to update media content while maintaining 100% identical URLs for SEO longevity.

---

## ✨ Key Features

- **SEO Preservation:** Physically overwrites existing files to maintain identical URLs.
- **Safe Staging:** Replacement files are stored in a secure, non-public directory until the Media entity is officially *Published*.
- **"Chameleon" UI:** Injects a custom replacement upload container directly into standard Media forms.
- **Surgical Lockdown:** Prevents editors from accidentally deleting "Live" files via the standard Drupal widget.
- **Dual-Engine Transfer:** Hot-swaps between `DrupalNativeTransfer` (for private stream wrappers) and `ManualTransfer` (native PHP logic for Docker/DDEV/Restricted environments).
- **Auto Cache-Busting:** Appends a persistent `?v=[mtime]` timestamp to images and document links to force browser/CDN refreshes without a hard refresh.
- **Ghost File Protection:** A 3-layer defense system that prevents physical file deletion if the replacement file is missing from the server.
- **Metadata Merging:** Automatically flushes stale image dimensions and focal point data during replacement to ensure native thumbnail regeneration.

---

## 🛠️ Requirements

- **Drupal:** ^11.3
- **PHP:** >=8.3
- **Modules:** `media`, `file`, `content_moderation` (optional but recommended).

---

## 🚀 Installation & Setup

1. **Enable the Module:**
   ```bash
   ddev drush en leap_file_manager
   ```
2. **Apply the Fields Recipe:**
   The module uses a "Double-Key" opt-in system. It will not touch any media type unless it has the internal tracker field attached. Run the provided recipe to attach these fields to your bundles:
   ```bash
   ddev drush recipe modules/custom/leap_file_manager/recipes/leap_file_manager
   ```
3. **Configure Storage:**
   Go to `/admin/config/media/leap-file-manager` to choose whether to use the `private://` stream wrapper or a custom public fallback directory for staged drafts.

---

## 🏗️ Architecture & Logic

### 1. The "Double-Key" Opt-in (`isManaged`)
The module is designed to be non-intrusive. It evaluates every media entity via the `isManaged()` method:
- **Key 1:** Does the Media bundle have the `field_leap_staged_file` tracker?
- **Key 2:** Does the Media bundle have a valid physical file source field (e.g., Image or File)?

If both keys aren't present, the module bails out, allowing standard Drupal behavior to take over.

### 2. Field Definitions

| Field Machine Name | Role | Requirement |
| :--- | :--- | :--- |
| `field_leap_staged_file` | **The Tracker.** Stores the FID of the replacement file while it's in Draft mode. | **Required for Opt-in** |
| `field_leap_keep_original` | **SEO Toggle.** If checked (default), the module forces the new file to take the old one's name. | Optional |
| `field_leap_rename_file` | **Rename Field.** Allows the editor to physically rename the file on disk. | Optional |

### 3. The "Chameleon" Form UI
When editing a managed Media entity, the `MediaFormHandler` performs a "Surgical Lockdown":
- The **Remove** button on the original file widget is hidden.
- The **Alt/Title** fields are made non-required at the widget level to allow saves during replacement.
- A **"Replace File"** details container is injected at the bottom of the source field.

### 4. The 3-Layer Ghost File Defense
To prevent accidental data loss (deleting the old file when the new one is missing), the module implements:
1. **The Shield:** Form validation blocks the "Publish" action if the staged file is missing from the disk.
2. **The Safety Net:** The orchestrator reorders operations to move the new file *before* deleting the old one.
3. **The Broom:** If a move fails, the orchestrator detaches the broken FID from the entity to prevent a loop of errors.

---

## 👨‍💻 Developer Information

### Custom Strategies
The module uses the **Strategy Pattern** to handle different media types. 
- `ImageStrategy`: Handles dimension clearing (`width`/`height`) and Image Style flushing.
- `FileStrategy`: Handles generic thumbnails and icons for PDFs/Documents.

### Transfer Engines
- `DrupalNativeTransfer`: Uses `FileRepository::move`. Best for sites with a properly configured `private://` system and standard directory permissions.
- `ManualTransfer`: Uses native PHP `rename()` and direct SQL updates. This is the "Sledgehammer" engine designed to bypass brittle stream wrapper permission issues in heavily restricted or deeply nested public-folder environments.

### Cache Busting
The module implements `hook_preprocess_image` and `hook_preprocess_file_link`. It calculates the physical `filemtime` of the source file and appends it as a query string (`?v=12345`). This ensures that as soon as a file is replaced, every visitor to the site sees the updated version immediately.

---

## 🛡️ License
This module is part of the Splash Frog Ecosystem, the Drupal Ecosystem, and is provided under the GPL-2.0-or-later License.
