# Makefile for KOReader Companion
app_name=koreader_companion
build_dir=$(CURDIR)/build
appstore_dir=$(build_dir)/artifacts/appstore
source_dir=$(build_dir)/artifacts/source
temp_dir=/tmp/$(app_name)-build
sign_dir=$(build_dir)/sign

# Clean build artifacts
clean:
	rm -rf $(build_dir)

# Install dependencies (if needed)
install:
	@echo "No dependencies to install for this app"

# Create app store tarball
appstore: clean
	mkdir -p "$(appstore_dir)"
	mkdir -p "$(source_dir)/$(app_name)"
	
	# Copy specific directories and files, excluding unwanted ones
	cp -r appinfo "$(source_dir)/$(app_name)/"
	cp -r css "$(source_dir)/$(app_name)/"
	cp -r img "$(source_dir)/$(app_name)/"
	cp -r js "$(source_dir)/$(app_name)/"
	cp -r lib "$(source_dir)/$(app_name)/"
	cp -r templates "$(source_dir)/$(app_name)/"
	test -f CHANGELOG.md && cp CHANGELOG.md "$(source_dir)/$(app_name)/" || true
	test -f LICENSE && cp LICENSE "$(source_dir)/$(app_name)/" || true
	
	# Create tarball for app store
	cd "$(source_dir)" && tar -czf "$(appstore_dir)/$(app_name).tar.gz" $(app_name)
	
	# Clean up source directory but keep tarball
	rm -rf "$(source_dir)"
	
	@echo "Tarball created at: $(appstore_dir)/$(app_name).tar.gz"

# Sign the app (for local development)
sign:
	@if [ -f ~/.nextcloud/certificates/$(app_name).key ] && [ -f ~/.nextcloud/certificates/$(app_name).crt ]; then \
		mkdir -p $(sign_dir); \
		cp -r ./ $(sign_dir)/$(app_name); \
		cd $(sign_dir)/$(app_name) && \
		php -f /path/to/nextcloud/occ integrity:sign-app \
			--privateKey=~/.nextcloud/certificates/$(app_name).key \
			--certificate=~/.nextcloud/certificates/$(app_name).crt \
			--path=$(sign_dir)/$(app_name); \
	else \
		echo "Certificate files not found. Please ensure ~/.nextcloud/certificates/$(app_name).key and $(app_name).crt exist"; \
	fi

# Build for release (equivalent to appstore target)
release: appstore

.PHONY: clean install appstore sign release