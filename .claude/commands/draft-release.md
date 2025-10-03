---
description: Analyze changes and create a draft GitHub release with intelligent version bumping
argument-hint: [force-version] (optional: major|minor|patch|x.y.z)
---

Create an intelligent draft GitHub release for the KOReader Companion Nextcloud app.

**Process:**
1. **Analyze Changes Since Last Release:**
   - Push all local commits to the remote
   - Get commits since last release/tag
   - Categorize changes (breaking changes, features, fixes, improvements)
   - Parse commit messages for conventional commit patterns

2. **Determine Version Bump:**
   - MAJOR: Breaking changes (BREAKING CHANGE:, ! suffix)
   - MINOR: New features (feat:)  
   - PATCH: Bug fixes (fix:), docs, chore, improvements
   - Use semantic versioning rules

3. **Generate Changelog:**
   - Group changes by category: Added, Changed, Improved, Fixed, Removed
   - Convert technical commit messages to user-friendly descriptions
   - Focus on what users can see and benefit from
   - Follow existing changelog style (high-level, non-technical)
   - **Use one concise bullet point per commit** - avoid over-categorization
   - **Do not use emojis** in release notes

4. **Update info.xml and CHANGELOG.md:**
   - Update the version in appinfo/info.xml
   - Update the changelog in CHANGELOG.md. use the keepachangelog syntax!

5. **Regenerate App Integrity Signature:**
   - **CRITICAL:** Run `./test_scripts/reset_and_deploy.sh` first to ensure container has latest code
   - Copy certificates to container: `docker cp ~/.nextcloud/certificates/koreader_companion.key nextcloudebooks-app-1:/tmp/ && docker cp ~/.nextcloud/certificates/koreader_companion.crt nextcloudebooks-app-1:/tmp/`
   - Fix permissions: `docker compose exec app chown www-data:www-data /tmp/koreader_companion.key /tmp/koreader_companion.crt`
   - Sign the app: `docker compose exec -u www-data app php occ integrity:sign-app --path=/var/www/html/apps/koreader_companion --privateKey=/tmp/koreader_companion.key --certificate=/tmp/koreader_companion.crt`
   - Copy signature back: `docker cp nextcloudebooks-app-1:/var/www/html/apps/koreader_companion/appinfo/signature.json ./appinfo/`

6. **Commit All Changes:**
   - Bundle all changes (info.xml, CHANGELOG.md, signature.json) in a single chore commit
   - Add all infrastructure changes to this commit (like updated to @CLAUDE.md etc.)
   - Commit message: "chore: prepare release vX.Y.Z"
   - Push changes to remote

7. **Create Draft:**
   - Create draft release with generated changelog (omit all references to claude code!)
   - Use tag format vX.Y.Z
   - **Do not use emojis** in release notes - keep formatting clean and professional
   - The release title should have this format: "vx.x.x"

**Arguments:**
- $ARGUMENTS: Optional version override
  - major, minor, patch - Force specific bump type
  - 1.0.28 - Set exact version
  - Empty - Auto-determine from commits

**Example usage:**
- /draft-release - Auto-analyze and bump version
- /draft-release minor - Force minor version bump
- /draft-release 1.1.0 - Set exact version

**Important:** Claude Code runs with `koreader-companion/` as the working directory.

**Implementation Steps:**
1. **Fetch all tags from remote**: `git fetch --tags`
2. Get last published release tag: `gh release view --json tagName --jq '.tagName'`
   - **CRITICAL:** Use GitHub CLI, NOT `git describe --tags --abbrev=0`
   - Git tags ≠ published GitHub releases
3. Analyze commits: git log --oneline {last-release-tag}..HEAD
   - Handle case where no commits exist since last release
4. Parse conventional commit patterns
5. Determine appropriate version bump
6. Update CHANGELOG.md & info.xml with new version
7. **Regenerate app integrity signature** (Step 5 above)
8. **Commit all changes in single chore commit** (Step 6 above)
9. Generate categorized changelog following existing format
10. Create draft release with GitHub CLI

**Key Commands:**
- Latest published release: `gh release view --json tagName --jq '.tagName'`
- NOT: `git describe --tags --abbrev=0` (gets latest git tag, not published release)