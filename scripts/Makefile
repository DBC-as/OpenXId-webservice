# ============================================================================
#			Literals
# ============================================================================

include literals
OCILOGON=$(subst /,\/,$(OCI_LOGON))
SRVLOGON=$(subst /,\/,$(SERVICE_LOGON))
OPENXID=$(subst /,\/,$(OPENXID_URL))
LOGFILE=$(subst /,\/,$(OPENXID_LOG))

# ============================================================================
#			Commands
# ============================================================================

CC=php -l

# ============================================================================
#			Objects
# ============================================================================

SOURCES=$(patsubst %.php,%.chk,$(wildcard *.php))

# ============================================================================
#			Targets
# ============================================================================

all: install compile test

compile: $(SOURCES)

%.chk: %.php
	$(CC) $<

test:
	@echo "Unit tests is not implemented yet!"

install:
	cp -f harvest.ini_INSTALL harvest.ini
	sed -i 's/^;*ocilogon[ ]*=[ ]*.*/ocilogon = $(OCILOGON)/' harvest.ini
	sed -i 's/^;*servicelogon[ ]*=[ ]*.*/servicelogon = $(SRVLOGON)/' harvest.ini
	sed -i 's/^;*openxidurl[ ]*=[ ]*.*/openxidurl = $(OPENXID)/' harvest.ini
	sed -i 's/^;*logfile[ ]*=[ ]*.*/logfile = $(LOGFILE)/' harvest.ini
	chmod -w harvest.ini
	chmod +x harvest

literals:
	@echo "Before installation, please copy literals_INSTALL to literals, and edit"
	@exit 1
