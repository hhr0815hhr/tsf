PHP_ARG_ENABLE([interp],
  [whether to enable interp support],
  [AS_HELP_STRING([--enable-interp], [Enable interp support])],
  [no])

if test "$PHP_INTERP" != "no"; then
  PHP_NEW_EXTENSION(interp, interp.c, $ext_shared)
fi
