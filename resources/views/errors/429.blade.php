@extends('errors.layout')

@section('title', 'Too many requests')
@section('code', '429')
@section('message', 'Too many requests')
@section('hint', 'Please slow down and try again in a moment.')