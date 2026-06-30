# FlowOne whitelabeled OnlyOffice Document Server image.
#
# Replaces the stock ONLYOFFICE header/loader logos with FlowOne branding
# at the asset level (config-based logo replacement is a paid Developer
# Edition feature; AGPL attribution in the About panel is kept).
#
# Build:  docker build -t flowone-onlyoffice:9.4 .
# Note:   re-run the build whenever the base image tag is bumped.

ARG ONLYOFFICE_TAG=9.4.0
FROM onlyoffice/documentserver:${ONLYOFFICE_TAG}

COPY branding/flowone-logo-dark.svg /tmp/branding/flowone-logo-dark.svg
COPY branding/flowone-logo-light.svg /tmp/branding/flowone-logo-light.svg

# Overwrite every header logo variant shipped with the editors.
# - header-logo*.svg     light UI header logos  -> dark FlowOne wordmark
# - dark-logo*.svg       dark UI header logos   -> light FlowOne wordmark
# The SVGs scale to whatever box the editor gives them.
RUN set -eux; \
    WEBAPPS=/var/www/onlyoffice/documentserver/web-apps; \
    find "$WEBAPPS" -type f -name 'header-logo*.svg' ! -name '*dark*' \
        -exec cp /tmp/branding/flowone-logo-dark.svg {} \; ; \
    find "$WEBAPPS" -type f \( -name 'dark-logo*.svg' -o -name 'header-logo*dark*.svg' \) \
        -exec cp /tmp/branding/flowone-logo-light.svg {} \; ; \
    rm -rf /tmp/branding

# Hide branded chrome (CE ignores the config flags for these, and the editor
# iframe is cross-origin so the host app cannot style it). Appending an
# override to every editor stylesheet is the only reliable CE-level approach.
# - section.logo/#header-logo : ONLYOFFICE header logo
# - #left-btn-about           : About button (left sidebar)
RUN set -eux; \
    WEBAPPS=/var/www/onlyoffice/documentserver/web-apps; \
    CSS='section.logo,#header-logo{display:none !important;width:0 !important;} #left-btn-about{display:none !important;}'; \
    find "$WEBAPPS/apps" -type f \( -name 'app.css' -o -name 'app.min.css' \) \
        -exec sh -c 'echo "$1" >> "$2"' _ "$CSS" {} \;

# FlowOne presence plugin (live cursors + follow mode). The backend's editor
# config autostarts it via editorConfig.plugins.pluginsData pointing at
# /sdkjs-plugins/flowone-presence/config.json on this server.
COPY plugins/flowone-presence /var/www/onlyoffice/documentserver/sdkjs-plugins/flowone-presence
RUN chown -R ds:ds /var/www/onlyoffice/documentserver/sdkjs-plugins/flowone-presence
