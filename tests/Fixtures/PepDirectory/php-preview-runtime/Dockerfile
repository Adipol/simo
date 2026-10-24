# Test-only derived runtime. Build offline from the verified existing local tags.
# PHP base ID: 71cf917d466a4fe99a1c21c47555654c651488233148ffd0bc4ab9587af2779e
# Donor ID: e5fc03d6ef46e90d4873f3c28a04460083f3489f45ab48bdb697a5bf28abf980
FROM simo-pep-directory-test:py312-shadow-v1 AS dependencies
FROM gentle/app:8.5

# Only pure-Python compiler dependencies; no donor interpreter or binary drivers.
COPY --from=dependencies /usr/local/lib/python3.12/site-packages/bs4/ /usr/local/lib/python3.12/dist-packages/bs4/
COPY --from=dependencies /usr/local/lib/python3.12/site-packages/beautifulsoup4-4.14.3.dist-info/ /usr/local/lib/python3.12/dist-packages/beautifulsoup4-4.14.3.dist-info/
COPY --from=dependencies /usr/local/lib/python3.12/site-packages/soupsieve/ /usr/local/lib/python3.12/dist-packages/soupsieve/
COPY --from=dependencies /usr/local/lib/python3.12/site-packages/soupsieve-2.8.4.dist-info/ /usr/local/lib/python3.12/dist-packages/soupsieve-2.8.4.dist-info/
COPY --from=dependencies /usr/local/lib/python3.12/site-packages/typing_extensions.py /usr/local/lib/python3.12/dist-packages/typing_extensions.py
COPY --from=dependencies /usr/local/lib/python3.12/site-packages/typing_extensions-4.15.0.dist-info/ /usr/local/lib/python3.12/dist-packages/typing_extensions-4.15.0.dist-info/
RUN /usr/bin/python3 -E -s -B -c "import bs4, soupsieve, typing_extensions; from importlib.metadata import version; assert [version(p) for p in ('beautifulsoup4', 'soupsieve', 'typing_extensions')] == ['4.14.3', '2.8.4', '4.15.0']; print('Pinned pure-Python dependencies available')"
WORKDIR /work
