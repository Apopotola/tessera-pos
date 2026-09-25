import { useDispatch, useSelector } from "react-redux";
import type { AppDispatch, RootState } from "@/store";

/** Typed hooks — use these instead of plain useDispatch/useSelector. */
export const useAppDispatch = useDispatch.withTypes<AppDispatch>();
export const useAppSelector = useSelector.withTypes<RootState>();
