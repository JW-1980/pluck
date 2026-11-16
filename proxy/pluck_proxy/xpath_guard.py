"""Validation of consumer-supplied keep-expressions (PROXY_CONTRACT §8; mirrors the donor guard
`XPathExpressionGuard`). Allowed: location paths, predicates, the core XPath 1.0 function set.
Rejected: extension functions (any prefixed name), variables, document()/XSLT constructs.

Complexity budget: the contract's 500-character cap per expression and 16 names per request, plus the
node/size caps on results. No further invented numeric budget exists in the contract."""
from __future__ import annotations

import re

from lxml import etree

from .errors import RequestError

# XPath 1.0 core function library + node tests (W3C XPath 1.0 §4, §2.3).
CORE_FUNCTIONS = frozenset({
    "last", "position", "count", "id", "local-name", "namespace-uri", "name", "string", "concat",
    "starts-with", "contains", "substring-before", "substring-after", "substring", "string-length",
    "normalize-space", "translate", "boolean", "not", "true", "false", "lang", "number", "sum",
    "floor", "ceiling", "round",
    "node", "text", "comment", "processing-instruction",
})
NAMESPACES = {"xhtml": "http://www.w3.org/1999/xhtml"}

_LITERALS = re.compile(r"'[^']*'|\"[^\"]*\"")
_CALL = re.compile(r"([A-Za-z_][\w.\-]*(?::[A-Za-z_][\w.\-]*)?)\s*\(")


def compile_expression(name: str, expression: str) -> etree.XPath:
    stripped = _LITERALS.sub("''", expression)
    if "$" in stripped:
        raise RequestError("invalid_xpath", f"keep_xpaths['{name}']: variables are not allowed.")
    for call in _CALL.findall(stripped):
        if ":" in call or call not in CORE_FUNCTIONS:
            raise RequestError("invalid_xpath", f"keep_xpaths['{name}']: function '{call}' is not allowed.")
    try:
        return etree.XPath(expression, namespaces=NAMESPACES)
    except etree.XPathSyntaxError as e:
        raise RequestError("invalid_xpath", f"keep_xpaths['{name}']: {e}") from None
    except etree.XPathError as e:
        raise RequestError("invalid_xpath", f"keep_xpaths['{name}']: {e}") from None
