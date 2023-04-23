#!/bin/sh
### BEGIN INIT INFO
# Provides:          mailpit
# Required-Start:    $local_fs $network $named $time $syslog
# Required-Stop:     $local_fs $network $named $time $syslog
# Default-Start:     2 3 4 5
# Default-Stop:      0 1 6
# Short-Description: starts mailpit service
# Description:       starts mailpit Resolution using start-stop-daemon
### END INIT INFO

# Original Author: Uttam Rabadiya
# Maintainer: Uttam Rabadiya

PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin
PIDFILE=/opt/valet-linux/mailpit.pid
LOGFILE=/opt/valet-linux/mailpit.log
DAEMON=/usr/local/bin/mailpit
NAME=mailpit
DESC="Mailpit Service"

STOP_SCHEDULE="${STOP_SCHEDULE:-QUIT/5/TERM/5/KILL/5}"

test -x $DAEMON || exit 0

. /lib/init/vars.sh
. /lib/lsb/init-functions

start() {
    test -f "$PIDFILE" && rm "$PIDFILE"
    start-stop-daemon --start --pidfile $PIDFILE --exec $DAEMON start || return 2
}

stop() {
    if [ -f "$PIDFILE" ]; then
        start-stop-daemon --stop --retry=$STOP_SCHEDULE --pidfile $PIDFILE && rm "$PIDFILE" || return 1
    fi

    test -f "$LOGFILE" && rm "$LOGFILE"
}

case "$1" in
    start)
        start
    ;;
    stop)
        stop
    ;;
    restart)
        stop
        start
    ;;
    status)
        exec $DAEMON status
    ;;
    *)
        echo "Usage: $0 {start|stop|restart|status}"
        exit 2
    ;;
esac

exit 0
