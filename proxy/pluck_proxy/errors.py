class RequestError(Exception):
    """A consumer bug: HTTP 400 with an error code (PROXY_CONTRACT §2)."""

    def __init__(self, code: str, message: str) -> None:
        super().__init__(message)
        self.code = code
        self.message = message
