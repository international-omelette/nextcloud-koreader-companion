# Makefile for KOReader Companion
app_name=$(notdir $(CURDIR))
build_dir=$(CURDIR)/build
appstore_dir=$(build_dir)/artifacts/appstore
source_dir=$(build_dir)/artifacts/source
sign_dir=$(build_dir)/sign

# Clean build artifacts
clean:
	rm -rf $(build_dir)

# Install dependencies (if needed)
install:
	@echo "No dependencies to install for this app"

# Create app store tarball
appstore: clean
	mkdir -p $(appstore_dir)
	mkdir -p $(source_dir)
	
	# Copy all files except development/build files
	rsync -a \
		--exclude='build' \
		--exclude='.git' \
		--exclude='.github' \
		--exclude='*.log' \
		--exclude='node_modules' \
		--exclude='Makefile' \
		--exclude='README.md' \
		./ $(source_dir)/$(app_name)
	
	# Create tarball for app store
	cd $(source_dir) && tar -czf $(appstore_dir)/$(app_name).tar.gz $(app_name)
	
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