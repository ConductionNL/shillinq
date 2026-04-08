# Makefile for nextcloud-app-template development

# Create a relative symlink in the parent directory so Nextcloud can find the
# app by its ID (app-template) even though the repo is cloned as nextcloud-app-template.
# Nextcloud requires the directory name to match the <id> in appinfo/info.xml.
dev-link:
	@if [ -L ../app-template ]; then \
		echo "Symlink ../app-template already exists."; \
	else \
		ln -s nextcloud-app-template ../app-template && \
		echo "Created symlink: apps-extra/app-template -> nextcloud-app-template"; \
	fi

dev-unlink:
	@if [ -L ../app-template ]; then \
		rm ../app-template && echo "Removed symlink ../app-template"; \
	else \
		echo "No symlink found at ../app-template."; \
	fi

.PHONY: dev-link dev-unlink
