#!/bin/sh

set -e

# Older Magento test matrices can include packages that Composer flags as insecure.
# We keep CI installable for compatibility verification by disabling hard blocking.
composer config --global --no-plugins audit.block-insecure false || true

