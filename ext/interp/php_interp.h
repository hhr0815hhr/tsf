#ifndef PHP_INTERP_H
#define PHP_INTERP_H

extern zend_module_entry interp_module_entry;
#define phpext_interp_ptr &interp_module_entry

#define PHP_INTERP_VERSION "0.5.0"

#endif
