"""Target validation (PROXY_CONTRACT §6): public http(s) only, judged from the proxy's own vantage,
and the vetted address is returned so the connection can be pinned to it."""
from __future__ import annotations

import asyncio
import ipaddress
import socket
from dataclasses import dataclass
from typing import Protocol
from urllib.parse import urlsplit

from .errors import RequestError


@dataclass(frozen=True)
class Target:
    scheme: str
    host: str
    port: int
    ip: str


class TargetPolicy(Protocol):
    async def vet(self, url: str) -> Target: ...


def is_public_ip(value: str) -> bool:
    try:
        ip = ipaddress.ip_address(value)
    except ValueError:
        return False
    if isinstance(ip, ipaddress.IPv6Address) and ip.ipv4_mapped is not None:
        ip = ip.ipv4_mapped
    # `is_global` is False for private, loopback, link-local (incl. 169.254.169.254), CGNAT
    # 100.64/10, documentation, benchmarking, reserved; multicast is excluded explicitly.
    return ip.is_global and not ip.is_multicast


class PublicTargetPolicy:
    async def vet(self, url: str) -> Target:
        parts = urlsplit(url)
        scheme = parts.scheme.lower()
        host = (parts.hostname or "").lower().rstrip(".")
        if scheme not in ("http", "https") or not host:
            raise RequestError("invalid_url", "Only absolute http(s) URLs can be rendered.")
        if parts.username is not None or parts.password is not None:
            raise RequestError("invalid_url", "Credentials in the URL are not allowed.")
        if host == "localhost" or host.endswith((".localhost", ".internal", ".local")):
            raise RequestError("private_target", "The target is not public.")

        port = parts.port or (443 if scheme == "https" else 80)
        try:
            ipaddress.ip_address(host)
            ips = [host]
        except ValueError:
            ips = await self._resolve(host, port)
        if not ips:
            raise RequestError("invalid_url", "The host did not resolve.")
        if not all(is_public_ip(ip) for ip in ips):
            raise RequestError("private_target", "The target resolves to a non-public address.")
        return Target(scheme, host, port, ips[0])

    @staticmethod
    async def _resolve(host: str, port: int) -> list[str]:
        loop = asyncio.get_running_loop()
        try:
            infos = await loop.getaddrinfo(host, port, type=socket.SOCK_STREAM)
        except socket.gaierror:
            return []
        seen: list[str] = []
        for info in infos:
            ip = info[4][0]
            if ip not in seen:
                seen.append(ip)
        return seen
