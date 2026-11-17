"""Bounded, in-memory runtime diagnostic. Does not read bills or issue forecasts."""
import json
import math
import platform
from importlib.metadata import version
from pathlib import Path

import numpy
import scipy
import sklearn
from sklearn.linear_model import LinearRegression
from sklearn.pipeline import make_pipeline
from sklearn.preprocessing import StandardScaler


def check():
    pins = dict(line.strip().split("==") for line in
                Path(__file__).with_name("requirements.txt").read_text().splitlines()
                if line.strip())
    packages = {name: version(name) for name in pins}
    model = make_pipeline(StandardScaler(), LinearRegression())
    model.fit([[0], [1], [2]], [0, 2, 4])
    result = float(model.predict([[3]])[0])
    return {"contract_version": 1, "python": platform.python_version(),
            "packages": packages, "pins_match": packages == pins,
            "estimator_ok": math.isfinite(result) and abs(result - 6) < 1e-9}


if __name__ == "__main__":
    print(json.dumps(check(), allow_nan=False))
