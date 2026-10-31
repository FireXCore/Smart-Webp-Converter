#!/usr/bin/env python3
"""Render reproducible WordPress.org PNG marketing assets from committed SVGs."""
from pathlib import Path
import argparse
import cairosvg

parser = argparse.ArgumentParser()
parser.add_argument("--output", default="wporg-svn/assets")
args = parser.parse_args()

root = Path(__file__).resolve().parent
out = Path(args.output)
out.mkdir(parents=True, exist_ok=True)
art = {
    "banner-1544x500.png": ("banner.svg", 1544, 500),
    "banner-772x250.png": ("banner.svg", 772, 250),
    "icon-256x256.png": ("icon.svg", 256, 256),
    "icon-128x128.png": ("icon.svg", 128, 128),
}
for dest, (src, width, height) in art.items():
    data = (root / src).read_bytes()
    cairosvg.svg2png(bytestring=data, write_to=str(out / dest), output_width=width, output_height=height)
    if (out / dest).stat().st_size < 1000:
        raise ValueError("Unexpected small PNG output: " + dest)
    print("PASS", dest, (out / dest).stat().st_size)
