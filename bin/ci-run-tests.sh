#!/bin/bash -eu

# Run the PHPUnit test suite in parallel with paratest, one copy of the
# CiviCRM, fredge and smashpig databases per worker. Expects
# ci-populate-dbs.sh to have run first.
#
# paratest gives each worker TEST_TOKEN=1..N. The rpow settings written by
# civibuild are changed so a process with a token connects to <db>_<token>,
# a copy of the freshly installed database. Without a token (phpunit, cv)
# the original databases are used.

CRM_ROOT=/src/wikimedia/fundraising/crm
CIVICRM_BUILDKIT="${CRM_ROOT}/civicrm-buildkit"
RPOW_SETTINGS="${CRM_ROOT}/private/civicrm.settings.d/pre.d/100-civirpow.php"
LOG_DIR="${LOG_DIR:-/log}"
WORKERS="${PARATEST_WORKERS:-6}"

if [ "${WORKSPACE}" != "" ]; then
  AMPHOME="${WORKSPACE}/.amp-${BUILD_NUMBER}"
else
  # CI with Docker container, same as ci-populate-dbs.sh
  AMPHOME="${XDG_CONFIG_HOME}/amp"
fi
export AMPHOME

cd "${CRM_ROOT}"
# Sets CIVI_DB_NAME, CIVI_DB_USER, ... for the database civibuild installed.
eval "$("${CIVICRM_BUILDKIT}"/bin/amp export -N civi --prefix=CIVI_)"

# Create database COPY from a dump of ORIGINAL, with the same grants.
create_copy() {
  local original=$1 copy=$2 dump=$3
  echo "Creating database '${copy}'"
  mysql -u root <<EOS
DROP DATABASE IF EXISTS ${copy};
CREATE DATABASE ${copy};
EOS
  mysql -u root "${copy}" < "${dump}"

  # Give every account with database level grants on the original database
  # (the rpow read-only user, the user civibuild creates has global grants)
  # the same grants on the copy.
  mysql -u root -N -e "SELECT CONCAT(\"'\", user, \"'@'\", host, \"'\") FROM mysql.db WHERE db = '${original}'" |
  while read -r account; do
    mysql -u root -N -e "SHOW GRANTS FOR ${account}" |
      grep "\`${original}\`" |
      sed "s/\`${original}\`/\`${copy}\`/; s/\$/;/" |
      mysql -u root
  done
}

# Views in the CiviCRM database select from these: payments_* from fredge,
# civicrm_transaction_log from smashpig.pending. Tests write through them.
SIDE_DBS="fredge smashpig"

echo "Dumping databases '${CIVI_DB_NAME}' ${SIDE_DBS}"
DUMP=$(mktemp)
mysqldump -u root --routines --triggers --no-tablespaces "${CIVI_DB_NAME}" > "${DUMP}"
for db in ${SIDE_DBS}; do
  mysqldump -u root --no-tablespaces "${db}" > "${DUMP}.${db}"
done

for i in $(seq 1 "${WORKERS}"); do
  cp "${DUMP}" "${DUMP}_${i}"
  for db in ${SIDE_DBS}; do
    create_copy "${db}" "${db}_${i}" "${DUMP}.${db}"
    # Point the views in this CiviCRM copy at the worker's own side copy.
    sed -i "s/\`${db}\`\./\`${db}_${i}\`./g" "${DUMP}_${i}"
  done
  create_copy "${CIVI_DB_NAME}" "${CIVI_DB_NAME}_${i}" "${DUMP}_${i}"
  rm -f "${DUMP}_${i}"
done
rm -f "${DUMP}" "${DUMP}".*

echo "Pointing paratest workers at their database copy in ${RPOW_SETTINGS}"
sed -i "s#/${CIVI_DB_NAME}?new_link=true'#/${CIVI_DB_NAME}' . (getenv('TEST_TOKEN') ? '_' . getenv('TEST_TOKEN') : '') . '?new_link=true'#g" "${RPOW_SETTINGS}"

echo "Running the test suite with ${WORKERS} paratest workers"
export PATH=$PATH:"${CIVICRM_BUILDKIT}"/bin
# WrapperRunner keeps one PHP process per worker instead of starting one per
# test class. --testdox lists the tests per class like the result printer in
# phpunit.xml does.
vendor/bin/paratest --processes="${WORKERS}" --runner=WrapperRunner --testdox --log-junit "${LOG_DIR}/junit-phpunit.xml"
